<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;

final class LicenseService
{
    public function activeLicenseForUser(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.*,p.code AS plan_code,p.name AS plan_name,p.type AS plan_type,
                    p.includes_updates,p.max_devices
             FROM licenses l
             JOIN plans p ON p.id=l.plan_id
             WHERE l.user_id=?
               AND l.status=\'active\'
               AND (l.expires_at IS NULL OR l.expires_at>NOW())
             ORDER BY l.created_at DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function activatePaidOrder(array $order): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $lock = $pdo->prepare(
                'SELECT co.*,p.type AS plan_type,p.code AS plan_code,p.name AS plan_name,
                        p.billing_interval,p.includes_updates
                 FROM checkout_orders co
                 JOIN plans p ON p.id=co.plan_id
                 WHERE co.id=? FOR UPDATE'
            );
            $lock->execute([(int) $order['id']]);
            $fresh = $lock->fetch();

            if (!$fresh) {
                throw new RuntimeException('Pedido não encontrado.');
            }
            if ($fresh['status'] !== 'paid') {
                throw new RuntimeException('Pedido ainda não está pago.');
            }
            if ((int) $fresh['license_applied'] === 1) {
                $pdo->commit();
                return;
            }

            $userId = (int) $fresh['user_id'];
            $planId = (int) $fresh['plan_id'];

            if ($fresh['plan_type'] === 'lifetime') {
                $version = Env::get('CAPTIONIA_CURRENT_VERSION', '3.2.3') ?: '3.2.3';
                $major = max(1, Env::int('CAPTIONIA_CURRENT_MAJOR', 3));

                $existing = $pdo->prepare(
                    'SELECT id FROM licenses
                     WHERE user_id=? AND plan_id=? AND status=\'active\' AND expires_at IS NULL
                     LIMIT 1 FOR UPDATE'
                );
                $existing->execute([$userId, $planId]);

                if (!$existing->fetchColumn()) {
                    $insert = $pdo->prepare(
                        'INSERT INTO licenses
                         (user_id,plan_id,status,purchased_version,max_major_version,expires_at,created_at,updated_at)
                         VALUES (?,?,\'active\',?,?,NULL,NOW(),NOW())'
                    );
                    $insert->execute([$userId, $planId, $version, $major]);
                }
            } else {
                $current = $pdo->prepare(
                    'SELECT id,expires_at FROM licenses
                     WHERE user_id=? AND plan_id=? AND status=\'active\'
                     ORDER BY expires_at DESC LIMIT 1 FOR UPDATE'
                );
                $current->execute([$userId, $planId]);
                $license = $current->fetch();

                $baseTs = time();
                if ($license && !empty($license['expires_at'])) {
                    $baseTs = max($baseTs, strtotime((string) $license['expires_at']) ?: 0);
                }
                $newExpiry = date('Y-m-d H:i:s', $baseTs + $this->subscriptionDurationSeconds((string) $fresh['billing_interval']));

                if ($license) {
                    [$version, $major] = $this->currentVersionInfo();
                    $pdo->prepare(
                        'UPDATE licenses
                         SET expires_at=?,status=\'active\',
                             purchased_version=CASE
                                 WHEN ?=0 AND purchased_version IS NULL THEN ?
                                 ELSE purchased_version
                             END,
                             max_major_version=CASE
                                 WHEN ?=0 AND max_major_version IS NULL THEN ?
                                 ELSE max_major_version
                             END,
                             updated_at=NOW()
                         WHERE id=?'
                    )->execute([
                        $newExpiry,
                        (int) ($fresh['includes_updates'] ?? 0),
                        $version,
                        (int) ($fresh['includes_updates'] ?? 0),
                        $major,
                        $license['id'],
                    ]);
                } else {
                    [$version, $major] = $this->currentVersionInfo();
                    $pdo->prepare(
                        'INSERT INTO licenses
                         (user_id,plan_id,status,purchased_version,max_major_version,expires_at,created_at,updated_at)
                         VALUES (?,?,\'active\',?,?,?,NOW(),NOW())'
                    )->execute([
                        $userId,
                        $planId,
                        (int) ($fresh['includes_updates'] ?? 0) === 1 ? null : $version,
                        (int) ($fresh['includes_updates'] ?? 0) === 1 ? null : $major,
                        $newExpiry,
                    ]);
                }

                $provider = str_starts_with((string) ($fresh['provider_mode'] ?? ''), 'asaas_')
                    ? 'asaas' : 'pagbank-pix';
                $providerId = $provider === 'asaas'
                    ? (string) $fresh['asaas_subscription_id']
                    : 'PIX-' . $userId . '-' . $planId;
                if ($providerId === '') {
                    throw new RuntimeException('Identificador da assinatura Asaas ausente.');
                }
                $pdo->prepare(
                    'INSERT INTO subscriptions
                     (user_id,plan_id,provider,provider_subscription_id,status,current_period_end,created_at,updated_at)
                     VALUES (?,?,?,?,\'active\',?,NOW(),NOW())
                     ON DUPLICATE KEY UPDATE
                     status=\'active\',current_period_end=VALUES(current_period_end),updated_at=NOW()'
                )->execute([$userId, $planId, $provider, $providerId, $newExpiry]);
            }

            $pdo->prepare(
                'UPDATE checkout_orders
                 SET license_applied=1,paid_at=COALESCE(paid_at,NOW()),updated_at=NOW()
                 WHERE id=?'
            )->execute([$fresh['id']]);

            $pdo->prepare(
                'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
                 VALUES (?,\'license.payment_applied\',?,NOW())'
            )->execute([
                $userId,
                json_encode([
                    'checkout_order_id' => (int) $fresh['id'],
                    'plan' => $fresh['plan_code'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function activateDevice(int $userId, string $deviceUuid, string $deviceName, string $os): array
    {
        $license = $this->activeLicenseForUser($userId);
        if (!$license) {
            throw new RuntimeException('Nenhuma licença ativa encontrada.');
        }

        $pdo = Database::connection();
        $existing = $pdo->prepare(
            'SELECT * FROM license_devices
             WHERE license_id=? AND device_uuid=? LIMIT 1'
        );
        $existing->execute([$license['id'], $deviceUuid]);
        $device = $existing->fetch();

        if ($device && $device['revoked_at'] === null) {
            $pdo->prepare(
                'UPDATE license_devices SET device_name=?,os=?,last_seen_at=NOW() WHERE id=?'
            )->execute([$deviceName, $os, $device['id']]);
            return $license;
        }

        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM license_devices
             WHERE license_id=? AND revoked_at IS NULL'
        );
        $count->execute([$license['id']]);
        if ((int) $count->fetchColumn() >= (int) $license['max_devices']) {
            throw new RuntimeException('Limite de dispositivos atingido.');
        }

        if ($device) {
            $pdo->prepare(
                'UPDATE license_devices
                 SET revoked_at=NULL,device_name=?,os=?,first_seen_at=NOW(),last_seen_at=NOW()
                 WHERE id=?'
            )->execute([$deviceName, $os, $device['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO license_devices
                 (license_id,device_uuid,device_name,os,first_seen_at,last_seen_at)
                 VALUES (?,?,?,?,NOW(),NOW())'
            )->execute([$license['id'], $deviceUuid, $deviceName, $os]);
        }

        return $license;
    }

    public function versionAllowed(array $license, string $version): bool
    {
        if ((int) ($license['includes_updates'] ?? 0) === 1) {
            return true;
        }

        $major = (int) explode('.', ltrim($version, 'vV'))[0];
        return $major > 0 && $major <= (int) ($license['max_major_version'] ?? 0);
    }

    public function grantManual(int $userId, string $planCode): void
    {
        $pdo = Database::connection();
        $plan = $pdo->prepare('SELECT * FROM plans WHERE code=? LIMIT 1');
        $plan->execute([$planCode]);
        $row = $plan->fetch();

        if (!$row) {
            throw new RuntimeException('Plano não encontrado.');
        }

        if ($row['type'] === 'lifetime') {
            [$version, $major] = $this->currentVersionInfo();
            $pdo->prepare(
                'INSERT INTO licenses
                 (user_id,plan_id,status,purchased_version,max_major_version,expires_at,created_at,updated_at)
                 VALUES (?,?,\'active\',?,?,NULL,NOW(),NOW())'
            )->execute([$userId, $row['id'], $version, $major]);
        } else {
            $expiry = date('Y-m-d H:i:s', time() + $this->subscriptionDurationSeconds((string) $row['billing_interval']));
            [$version, $major] = $this->currentVersionInfo();
            $pdo->prepare(
                'INSERT INTO licenses
                 (user_id,plan_id,status,purchased_version,max_major_version,expires_at,created_at,updated_at)
                 VALUES (?,?,\'active\',?,?,?,NOW(),NOW())'
            )->execute([
                $userId,
                $row['id'],
                (int) ($row['includes_updates'] ?? 0) === 1 ? null : $version,
                (int) ($row['includes_updates'] ?? 0) === 1 ? null : $major,
                $expiry,
            ]);
        }
    }

    private function currentVersionInfo(): array
    {
        try {
            $row = Database::connection()
                ->query(
                    "SELECT version,major_version
                     FROM releases
                     WHERE active=1
                     ORDER BY published_at DESC,id DESC
                     LIMIT 1"
                )
                ->fetch();

            if ($row) {
                return [(string) $row['version'], max(1, (int) $row['major_version'])];
            }
        } catch (\Throwable) {
        }

        $version = Env::get('CAPTIONIA_CURRENT_VERSION', '3.3.0') ?: '3.3.0';
        $major = max(1, Env::int('CAPTIONIA_CURRENT_MAJOR', (int) explode('.', ltrim($version, 'vV'))[0]));
        return [$version, $major];
    }

    private function subscriptionDurationSeconds(string $billingInterval): int
    {
        return match ($billingInterval) {
            'year' => 365 * 86400,
            'month' => 30 * 86400,
            default => throw new RuntimeException('Intervalo de cobrança inválido para licença por assinatura.'),
        };
    }

    public function setStatus(int $licenseId, string $status): void
    {
        if (!in_array($status, ['active', 'revoked'], true)) {
            throw new RuntimeException('Status inválido.');
        }

        Database::connection()
            ->prepare('UPDATE licenses SET status=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, $licenseId]);
    }

    public function resetDevices(int $licenseId): void
    {
        Database::connection()
            ->prepare('UPDATE license_devices SET revoked_at=NOW() WHERE license_id=? AND revoked_at IS NULL')
            ->execute([$licenseId]);
    }
}
