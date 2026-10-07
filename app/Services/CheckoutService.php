<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;

final class CheckoutService
{
    public function __construct(
        private readonly string $basePath,
        private readonly PagBankService $pagBank,
        private readonly LicenseService $licenses
    ) {
    }

    public function planByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1'
        );
        $stmt->execute([$code]);
        $plan = $stmt->fetch();
        return $plan ?: null;
    }

    public function createPixForUser(array $user, array $plan): array
    {
        if (!$this->pagBank->configured()) {
            throw new RuntimeException('O pagamento PIX ainda não foi configurado no servidor.');
        }

        $reference = 'CIA-' . strtoupper(bin2hex(random_bytes(8)));
        $pdo = Database::connection();
        $minutes = max(10, min(1440, Env::int('PAGBANK_PIX_EXPIRATION_MINUTES', 30)));
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

        $insert = $pdo->prepare(
            'INSERT INTO checkout_orders
             (reference_id,user_id,plan_id,status,amount_cents,expires_at,created_at,updated_at)
             VALUES (?,?,?,\'creating\',?,?,NOW(),NOW())'
        );
        $insert->execute([
            $reference,
            $user['id'],
            $plan['id'],
            $plan['price_cents'],
            $expiresAt,
        ]);
        $checkoutId = (int) $pdo->lastInsertId();

        try {
            $notificationUrl = rtrim(Env::get('APP_URL', 'https://caption.escotilhaprodutora.com') ?: '', '/')
                . '/webhooks/pagbank';

            $response = $this->pagBank->createPixOrder($user, $plan, $reference, $notificationUrl);
            $charge = $response['charges'][0] ?? [];
            $status = strtoupper((string) ($charge['status'] ?? 'WAITING'));
            $localStatus = $this->mapStatus($status);
            $qrText = (string) ($charge['qr_code']['text'] ?? '');
            $qrImage = '';

            foreach (($charge['links'] ?? []) as $link) {
                if (($link['rel'] ?? '') === 'QRCODE.PNG') {
                    $qrImage = (string) ($link['href'] ?? '');
                    break;
                }
            }

            $pdo->prepare(
                'UPDATE checkout_orders
                 SET pagbank_order_id=?,pagbank_charge_id=?,status=?,qr_code_text=?,
                     qr_code_image_url=?,raw_response_json=?,updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $response['id'] ?? null,
                $charge['id'] ?? null,
                $localStatus,
                $qrText ?: null,
                $qrImage ?: null,
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $checkoutId,
            ]);

            $order = $this->findByReference($reference);
            if ($order && $localStatus === 'paid') {
                $this->recordPayment($order, $response);
                $this->licenses->activatePaidOrder($order);
                $order = $this->findByReference($reference);
            }

            return $order ?? throw new RuntimeException('Não foi possível carregar o pedido criado.');
        } catch (\Throwable $e) {
            $pdo->prepare(
                'UPDATE checkout_orders SET status=\'failed\',updated_at=NOW() WHERE id=?'
            )->execute([$checkoutId]);
            throw $e;
        }
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT co.*,p.code AS plan_code,p.name AS plan_name,p.type AS plan_type,
                    p.includes_updates,p.max_devices,
                    u.name AS customer_name,u.email AS customer_email,u.cpf,u.phone
             FROM checkout_orders co
             JOIN plans p ON p.id=co.plan_id
             JOIN users u ON u.id=co.user_id
             WHERE co.reference_id=?
             LIMIT 1'
        );
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function sync(string $reference): ?array
    {
        $order = $this->findByReference($reference);
        if (!$order) {
            return null;
        }

        if (
            in_array($order['status'], ['creating', 'waiting'], true)
            && !empty($order['pagbank_order_id'])
            && $this->pagBank->configured()
        ) {
            try {
                $payload = $this->pagBank->getOrder((string) $order['pagbank_order_id']);
                $this->processPayload($payload, 'poll');
            } catch (\Throwable) {
            }

            $order = $this->findByReference($reference);
        }

        if ($order && $order['status'] === 'waiting' && strtotime((string) $order['expires_at']) < time()) {
            Database::connection()
                ->prepare('UPDATE checkout_orders SET status=\'expired\',updated_at=NOW() WHERE id=?')
                ->execute([$order['id']]);
            $order = $this->findByReference($reference);
        }

        return $order;
    }

    public function processWebhook(string $rawBody, string $signature): array
    {
        if (!$this->pagBank->verifyWebhook($rawBody, $signature)) {
            throw new RuntimeException('Assinatura do webhook PagBank inválida.');
        }

        $hash = hash('sha256', $rawBody . '|' . $signature);
        $pdo = Database::connection();

        $exists = $pdo->prepare(
            'SELECT status FROM webhook_events WHERE provider=\'pagbank\' AND event_hash=? LIMIT 1'
        );
        $exists->execute([$hash]);
        $previous = $exists->fetchColumn();
        if ($previous === 'processed') {
            return ['duplicate' => true];
        }

        $pdo->prepare(
            'INSERT INTO webhook_events
             (provider,event_hash,status,payload_json,created_at)
             VALUES (\'pagbank\',?,\'received\',?,NOW())
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)'
        )->execute([$hash, $rawBody]);

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            $order = $this->processPayload($payload, 'webhook');

            $pdo->prepare(
                'UPDATE webhook_events SET status=\'processed\',processed_at=NOW(),error_message=NULL
                 WHERE provider=\'pagbank\' AND event_hash=?'
            )->execute([$hash]);

            return ['duplicate' => false, 'order' => $order];
        } catch (\Throwable $e) {
            $pdo->prepare(
                'UPDATE webhook_events SET status=\'failed\',error_message=?,processed_at=NOW()
                 WHERE provider=\'pagbank\' AND event_hash=?'
            )->execute([mb_substr($e->getMessage(), 0, 500), $hash]);
            throw $e;
        }
    }

    public function processPayload(array $payload, string $source): ?array
    {
        $charge = $payload['charges'][0] ?? null;
        $reference = (string) ($payload['reference_id'] ?? '');

        if ($reference === '' && is_array($charge)) {
            $reference = (string) ($charge['reference_id'] ?? '');
        }
        if ($reference === '') {
            return null;
        }

        $order = $this->findByReference($reference);
        if (!$order) {
            return null;
        }

        $status = strtoupper((string) (is_array($charge) ? ($charge['status'] ?? '') : ''));
        if ($status === '') {
            return $order;
        }

        $local = $this->mapStatus($status);
        $amount = is_array($charge) ? (int) ($charge['amount']['value'] ?? 0) : 0;

        if ($local === 'paid' && $amount !== (int) $order['amount_cents']) {
            throw new RuntimeException('Valor do pagamento não corresponde ao pedido.');
        }

        Database::connection()->prepare(
            'UPDATE checkout_orders
             SET status=?,
                 pagbank_order_id=COALESCE(?,pagbank_order_id),
                 pagbank_charge_id=COALESCE(?,pagbank_charge_id),
                 raw_webhook_json=CASE WHEN ?=\'webhook\' THEN ? ELSE raw_webhook_json END,
                 paid_at=CASE WHEN ?=\'paid\' THEN COALESCE(paid_at,NOW()) ELSE paid_at END,
                 updated_at=NOW()
             WHERE id=?'
        )->execute([
            $local,
            $payload['id'] ?? null,
            is_array($charge) ? ($charge['id'] ?? null) : null,
            $source,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $local,
            $order['id'],
        ]);

        $fresh = $this->findByReference($reference);
        if ($fresh && $local === 'paid') {
            $this->recordPayment($fresh, $payload);
            $this->licenses->activatePaidOrder($fresh);
            $fresh = $this->findByReference($reference);
        }

        return $fresh;
    }

    public function ordersForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT co.*,p.code AS plan_code,p.name AS plan_name
             FROM checkout_orders co
             JOIN plans p ON p.id=co.plan_id
             WHERE co.user_id=?
             ORDER BY co.created_at DESC
             LIMIT 20'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function qrImage(array $order): array
    {
        $url = (string) ($order['qr_code_image_url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('QR Code indisponível.');
        }

        return $this->pagBank->fetchBinary($url);
    }

    private function recordPayment(array $order, array $payload): void
    {
        $charge = $payload['charges'][0] ?? [];
        $paymentId = (string) ($charge['id'] ?? $order['pagbank_charge_id'] ?? '');
        if ($paymentId === '') {
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO payment_transactions
             (user_id,provider,provider_payment_id,amount_cents,status,payload_json,created_at)
             VALUES (?,\'pagbank\',?,?,\'paid\',?,NOW())
             ON DUPLICATE KEY UPDATE status=\'paid\',payload_json=VALUES(payload_json)'
        )->execute([
            $order['user_id'],
            $paymentId,
            $order['amount_cents'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'PAID' => 'paid',
            'DECLINED' => 'declined',
            'CANCELED', 'CANCELLED' => 'cancelled',
            default => 'waiting',
        };
    }
}
