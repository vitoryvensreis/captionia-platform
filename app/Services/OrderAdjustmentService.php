<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Administrative correction of access granted after an already-paid order.
 *
 * Accounting stays immutable: checkout_orders, gateway totals, transaction
 * receipts and affiliate commissions are never modified by this service.
 */
final class OrderAdjustmentService
{
    public function findForAdmin(string $reference): ?array
    {
        $pdo = Database::connection();
        $query = $pdo->prepare(
            'SELECT co.id, co.reference_id, co.user_id, co.plan_id, co.amount_cents,
                    co.status, co.license_applied, co.provider_mode, co.paid_at, co.created_at,
                    u.name AS buyer_name, u.email AS buyer_email,
                    p.name AS purchased_plan_name, p.type AS purchased_plan_type
             FROM checkout_orders co
             JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             WHERE co.reference_id=? LIMIT 1'
        );
        $query->execute([$reference]);
        $order = $query->fetch();
        if (!$order) return null;

        $licenses = $pdo->prepare(
            'SELECT l.id, l.plan_id, l.status, l.expires_at, l.purchased_version, l.max_major_version,
                    p.name AS plan_name, p.type AS plan_type, p.includes_updates
             FROM licenses l JOIN plans p ON p.id=l.plan_id
             WHERE l.user_id=? ORDER BY l.status=\'active\' DESC, l.created_at DESC LIMIT 40'
        );
        $licenses->execute([(int)$order['user_id']]);

        $history = $pdo->prepare(
            'SELECT a.id,a.license_id,a.old_expires_at,a.new_expires_at,a.reason,a.created_at,
                    old_plan.name AS old_plan, new_plan.name AS new_plan,
                    admin.name AS admin_name
             FROM license_access_adjustments a
             JOIN plans old_plan ON old_plan.id=a.old_plan_id
             JOIN plans new_plan ON new_plan.id=a.new_plan_id
             JOIN users admin ON admin.id=a.admin_user_id
             WHERE a.order_id=? ORDER BY a.id DESC LIMIT 50'
        );
        $history->execute([(int)$order['id']]);

        return [
            'order' => $order,
            'licenses' => $licenses->fetchAll(),
            'history' => $history->fetchAll(),
            'plans' => $pdo->query(
                'SELECT id,name,code,type,billing_interval,includes_updates,max_devices,active
                 FROM plans ORDER BY active DESC,sort_order,id'
            )->fetchAll(),
        ];
    }

    /**
     * Explicit license selection is required, because an account can have
     * multiple licenses from separate purchases.
     */
    public function changeAccess(
        string $reference,
        int $licenseId,
        int $newPlanId,
        ?string $expiryDate,
        string $reason,
        int $adminId,
        int $expectedPlanId,
        ?string $expectedExpiry
    ): void {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Informe a justificativa da alteração (10 a 500 caracteres).');
        }
        if ($licenseId <= 0 || $newPlanId <= 0 || $expectedPlanId <= 0 || $adminId <= 0) {
            throw new RuntimeException('Seleção de licença ou plano inválida.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Lock the payment first; the original receipt is read-only.
            $stmt = $pdo->prepare('SELECT * FROM checkout_orders WHERE reference_id=? FOR UPDATE');
            $stmt->execute([$reference]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Compra não encontrada.');
            if ($order['status'] !== 'paid' || (int)$order['license_applied'] !== 1) {
                throw new RuntimeException('Ajustes de acesso exigem compra paga com licença aplicada. Não altere cobranças pendentes.');
            }

            $stmt = $pdo->prepare(
                'SELECT l.*,p.name AS old_plan_name,p.type AS old_plan_type
                 FROM licenses l
                 JOIN plans p ON p.id=l.plan_id
                 WHERE l.id=? AND l.user_id=? FOR UPDATE'
            );
            $stmt->execute([$licenseId,(int)$order['user_id']]);
            $license = $stmt->fetch();
            if (!$license || $license['status'] !== 'active'
                || ($license['expires_at'] !== null && strtotime((string)$license['expires_at']) <= time())) {
                throw new RuntimeException('Selecione uma licença ativa e não expirada pertencente ao comprador.');
            }
            // Prevent stale browser forms overwriting a more recent manual edit.
            if ((int)$license['plan_id'] !== $expectedPlanId
                || ($license['expires_at'] ?? null) !== $expectedExpiry) {
                throw new RuntimeException('A licença mudou desde que a página foi aberta. Atualize e tente novamente.');
            }

            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=? LIMIT 1');
            $stmt->execute([$newPlanId]);
            $plan = $stmt->fetch();
            if (!$plan) throw new RuntimeException('Plano selecionado não encontrado.');

            $newExpiry = $this->resolveExpiry($plan, $expiryDate);

            $activeDeviceStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM license_devices WHERE license_id=? AND revoked_at IS NULL'
            );
            $activeDeviceStmt->execute([$licenseId]);
            if ((int)$activeDeviceStmt->fetchColumn() > (int)$plan['max_devices']) {
                throw new RuntimeException('A licença possui mais computadores ativos do que o novo plano permite. Revogue dispositivos antes do ajuste.');
            }
            if ((int)$license['plan_id'] === $newPlanId
                && ($license['expires_at'] ?? null) === $newExpiry) {
                throw new RuntimeException('Nenhuma mudança identificada no plano ou validade da licença.');
            }

            [$version, $major] = $this->versionInformation();
            $hasUpdates = (int)$plan['includes_updates'] === 1;
            $purchasedVersion = $hasUpdates ? null : ($license['purchased_version'] ?: $version);
            $maxMajor = $hasUpdates ? null : ($license['max_major_version'] ?: $major);

            $pdo->prepare(
                'UPDATE licenses
                 SET plan_id=?, expires_at=?, purchased_version=?, max_major_version=?, updated_at=NOW()
                 WHERE id=? AND user_id=?'
            )->execute([
                $newPlanId,$newExpiry,$purchasedVersion,$maxMajor,$licenseId,(int)$order['user_id']
            ]);

            $pdo->prepare(
                'INSERT INTO license_access_adjustments
                  (order_id,license_id,admin_user_id,old_plan_id,new_plan_id,old_expires_at,new_expires_at,reason)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                (int)$order['id'],$licenseId,$adminId,
                (int)$license['plan_id'],$newPlanId,$license['expires_at'],$newExpiry,$reason
            ]);

            $pdo->prepare(
                'INSERT INTO audit_logs(user_id,event_type,metadata_json,created_at)
                 VALUES (?,\'license.admin_access_adjusted\',?,NOW())'
            )->execute([
                $adminId,
                json_encode([
                    'order_id'=>(int)$order['id'],
                    'license_id'=>$licenseId,
                    'buyer_user_id'=>(int)$order['user_id'],
                    'from_plan_id'=>(int)$license['plan_id'],
                    'to_plan_id'=>$newPlanId,
                    'from_expiry'=>$license['expires_at'],
                    'to_expiry'=>$newExpiry,
                    'adjustment_reason'=>$reason
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function resolveExpiry(array $plan, ?string $input): ?string
    {
        if (($plan['type'] ?? '') === 'lifetime') return null;
        if (($plan['type'] ?? '') !== 'subscription') {
            throw new RuntimeException('Tipo do plano não suportado.');
        }
        $input = trim((string)$input);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) {
            throw new RuntimeException('Para um plano por assinatura, informe a data de vencimento.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if (!$date || $date->format('Y-m-d') !== $input) {
            throw new RuntimeException('Data de vencimento inválida.');
        }
        $today = new DateTimeImmutable('today');
        if ($date < $today || $date > $today->modify('+5 years')) {
            throw new RuntimeException('Escolha um vencimento de hoje até cinco anos no futuro.');
        }
        return $date->format('Y-m-d') . ' 23:59:59';
    }

    private function versionInformation(): array
    {
        $row = Database::connection()->query(
            'SELECT version,major_version FROM releases
             WHERE active=1 ORDER BY published_at DESC,id DESC LIMIT 1'
        )->fetch();
        if ($row) return [(string)$row['version'],max(1,(int)$row['major_version'])];
        $version = Env::get('CAPTIONIA_CURRENT_VERSION','3.4.3') ?: '3.4.3';
        return [$version,max(1,(int)explode('.',ltrim($version,'vV'))[0])];
    }
}
