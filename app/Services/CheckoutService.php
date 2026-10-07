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
        private readonly LicenseService $licenses,
        private readonly SettingsService $settings,
        private readonly PixBacenService $pixBacen
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

    public function createForUser(array $user, array $plan): array
    {
        return $this->settings->paymentGateway() === 'bacen_pix'
            ? $this->createBacenPixForUser($user, $plan)
            : $this->createHostedCheckoutForUser($user, $plan);
    }

    public function createBacenPixForUser(array $user, array $plan): array
    {
        $pixKey = $this->settings->pixKey();
        if ($pixKey === '') {
            throw new RuntimeException('O Pix direto ainda não possui uma chave configurada no painel Admin.');
        }

        $reference = 'CIA-' . strtoupper(bin2hex(random_bytes(8)));
        $minutes = max(30, min(10080, Env::int('BACEN_PIX_ORDER_EXPIRATION_MINUTES', 1440)));
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));
        $description = $plan['code'] === 'monthly' ? 'Caption IA Mensal' : 'Caption IA Vitalicio';

        $payload = $this->pixBacen->payload(
            $pixKey,
            (int) $plan['price_cents'],
            $this->settings->pixName(),
            $this->settings->pixCity(),
            $reference,
            $description
        );

        if (!$this->pixBacen->validatePayload($payload)) {
            throw new RuntimeException('Falha ao validar o BR Code Pix gerado.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO checkout_orders
             (reference_id,user_id,plan_id,status,amount_cents,provider_mode,qr_code_text,expires_at,created_at,updated_at)
             VALUES (?,?,?,\'waiting\',?,\'bacen_static_pix\',?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $reference,
            $user['id'],
            $plan['id'],
            $plan['price_cents'],
            $payload,
            $expiresAt,
        ]);

        return $this->findByReference($reference)
            ?? throw new RuntimeException('Não foi possível criar o pagamento Pix.');
    }

    public function createHostedCheckoutForUser(array $user, array $plan): array
    {
        if (!$this->pagBank->configured()) {
            throw new RuntimeException('O PagBank ainda não foi configurado no servidor.');
        }

        $reference = 'CIA-' . strtoupper(bin2hex(random_bytes(8)));
        $pdo = Database::connection();
        $minutes = max(10, min(1440, Env::int('PAGBANK_PIX_EXPIRATION_MINUTES', 30)));
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

        $insert = $pdo->prepare(
            'INSERT INTO checkout_orders
             (reference_id,user_id,plan_id,status,amount_cents,provider_mode,expires_at,created_at,updated_at)
             VALUES (?,?,?,\'creating\',?,\'hosted_checkout\',?,NOW(),NOW())'
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
            $appUrl = rtrim(Env::get('APP_URL', 'https://caption.escotilhaprodutora.com') ?: '', '/');
            $notificationUrl = $appUrl . '/webhooks/pagbank';
            $returnUrl = $appUrl . '/pagamento/' . rawurlencode($reference);

            $response = $this->pagBank->createHostedCheckout(
                $user,
                $plan,
                $reference,
                $notificationUrl,
                $returnUrl
            );

            $hostedUrl = $this->pagBank->hostedPayUrl($response);
            if (!$hostedUrl) {
                throw new RuntimeException('PagBank não retornou o link do checkout.');
            }

            $pdo->prepare(
                'UPDATE checkout_orders
                 SET pagbank_checkout_id=?,hosted_checkout_url=?,status=\'waiting\',
                     raw_response_json=?,updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $response['id'] ?? null,
                $hostedUrl,
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $checkoutId,
            ]);

            return $this->findByReference($reference)
                ?? throw new RuntimeException('Não foi possível carregar o checkout criado.');
        } catch (\Throwable $e) {
            $pdo->prepare(
                'UPDATE checkout_orders SET status=\'failed\',updated_at=NOW() WHERE id=?'
            )->execute([$checkoutId]);
            throw $e;
        }
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
             (reference_id,user_id,plan_id,status,amount_cents,provider_mode,expires_at,created_at,updated_at)
             VALUES (?,?,?,\'creating\',?,\'transparent_pix\',?,NOW(),NOW())'
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
            && $this->pagBank->configured()
        ) {
            try {
                if (
                    ($order['provider_mode'] ?? '') === 'hosted_checkout'
                    && !empty($order['pagbank_checkout_id'])
                ) {
                    $payload = $this->pagBank->getCheckout((string) $order['pagbank_checkout_id']);
                    $this->processPayload($payload, 'poll');
                } elseif (!empty($order['pagbank_order_id'])) {
                    $payload = $this->pagBank->getOrder((string) $order['pagbank_order_id']);
                    $this->processPayload($payload, 'poll');
                }
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
        $payment = $this->extractPayment($payload);
        $reference = (string) ($payload['reference_id'] ?? '');

        if ($reference === '' && $payment) {
            $reference = (string) ($payment['reference_id'] ?? '');
        }

        $order = $reference !== '' ? $this->findByReference($reference) : null;

        $payloadId = (string) ($payload['id'] ?? '');
        if (!$order && str_starts_with($payloadId, 'CHEC_')) {
            $stmt = Database::connection()->prepare(
                'SELECT co.reference_id
                 FROM checkout_orders co
                 WHERE co.pagbank_checkout_id=?
                 LIMIT 1'
            );
            $stmt->execute([$payloadId]);
            $foundReference = (string) ($stmt->fetchColumn() ?: '');
            if ($foundReference !== '') {
                $order = $this->findByReference($foundReference);
                $reference = $foundReference;
            }
        }

        if (!$order) {
            return null;
        }

        $checkoutStatus = strtoupper((string) ($payload['status'] ?? ''));
        $local = $payment
            ? $this->mapStatus((string) ($payment['status'] ?? ''))
            : ($checkoutStatus === 'EXPIRED' ? 'expired' : $order['status']);

        $amount = $payment ? (int) ($payment['amount']['value'] ?? 0) : 0;

        if ($local === 'paid') {
            if ($amount <= 0 || $amount !== (int) $order['amount_cents']) {
                throw new RuntimeException('Valor do pagamento não corresponde ao pedido.');
            }
        }

        $pagbankOrderId = str_starts_with($payloadId, 'ORDE_') ? $payloadId : null;
        $pagbankCheckoutId = str_starts_with($payloadId, 'CHEC_') ? $payloadId : null;
        $paymentId = $payment ? (string) ($payment['id'] ?? '') : '';

        Database::connection()->prepare(
            'UPDATE checkout_orders
             SET status=?,
                 pagbank_order_id=COALESCE(?,pagbank_order_id),
                 pagbank_checkout_id=COALESCE(?,pagbank_checkout_id),
                 pagbank_charge_id=COALESCE(NULLIF(?,\'\'),pagbank_charge_id),
                 raw_webhook_json=CASE WHEN ?=\'webhook\' THEN ? ELSE raw_webhook_json END,
                 paid_at=CASE WHEN ?=\'paid\' THEN COALESCE(paid_at,NOW()) ELSE paid_at END,
                 updated_at=NOW()
             WHERE id=?'
        )->execute([
            $local,
            $pagbankOrderId,
            $pagbankCheckoutId,
            $paymentId,
            $source,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $local,
            $order['id'],
        ]);

        $fresh = $this->findByReference($reference);
        if ($fresh && $local === 'paid') {
            $this->recordPayment($fresh, $payload, $payment);
            $this->licenses->activatePaidOrder($fresh);
            $fresh = $this->findByReference($reference);
        }

        return $fresh;
    }

    public function confirmManualBacenPayment(string $reference, int $adminUserId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM checkout_orders WHERE reference_id=? FOR UPDATE'
            );
            $stmt->execute([$reference]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new RuntimeException('Pagamento não encontrado.');
            }
            if (($order['provider_mode'] ?? '') !== 'bacen_static_pix') {
                throw new RuntimeException('Este pagamento não é um Pix direto.');
            }

            if ($order['status'] !== 'paid') {
                $pdo->prepare(
                    'UPDATE checkout_orders
                     SET status=\'paid\',paid_at=COALESCE(paid_at,NOW()),updated_at=NOW()
                     WHERE id=?'
                )->execute([$order['id']]);

                $paymentId = 'BACEN-' . $reference;
                $pdo->prepare(
                    'INSERT INTO payment_transactions
                     (user_id,provider,provider_payment_id,amount_cents,status,payload_json,created_at)
                     VALUES (?,\'bacen_static_pix\',?,?,\'paid\',?,NOW())
                     ON DUPLICATE KEY UPDATE status=\'paid\',payload_json=VALUES(payload_json)'
                )->execute([
                    $order['user_id'],
                    $paymentId,
                    $order['amount_cents'],
                    json_encode([
                        'reference_id' => $reference,
                        'confirmed_by' => $adminUserId,
                        'mode' => 'manual_admin_confirmation',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $pdo->prepare(
                    'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
                     VALUES (?,\'payment.manual_pix_confirmed\',?,NOW())'
                )->execute([
                    $adminUserId,
                    json_encode([
                        'reference_id' => $reference,
                        'customer_user_id' => (int) $order['user_id'],
                        'amount_cents' => (int) $order['amount_cents'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $pdo->commit();

            $fresh = $this->findByReference($reference)
                ?? throw new RuntimeException('Pagamento não encontrado após confirmação.');

            $this->licenses->activatePaidOrder($fresh);

            return $this->findByReference($reference) ?? $fresh;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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

    private function recordPayment(array $order, array $payload, ?array $payment = null): void
    {
        $payment ??= $this->extractPayment($payload);
        $paymentId = (string) (
            ($payment['id'] ?? '')
            ?: ($order['pagbank_charge_id'] ?? '')
            ?: ($order['pagbank_order_id'] ?? '')
        );

        if ($paymentId === '') {
            throw new RuntimeException('Pagamento confirmado sem identificador do PagBank.');
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

    private function extractPayment(array $payload): ?array
    {
        $known = ['PAID', 'WAITING', 'DECLINED', 'CANCELED', 'CANCELLED', 'IN_ANALYSIS', 'AUTHORIZED'];

        $walk = function (mixed $node) use (&$walk, $known): ?array {
            if (!is_array($node)) {
                return null;
            }

            $status = strtoupper((string) ($node['status'] ?? ''));
            if (
                in_array($status, $known, true)
                && (
                    isset($node['amount'])
                    || str_starts_with((string) ($node['id'] ?? ''), 'CHAR_')
                )
            ) {
                return $node;
            }

            $paidCandidate = null;
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $candidate = $walk($value);
                if (!$candidate) {
                    continue;
                }

                if (strtoupper((string) ($candidate['status'] ?? '')) === 'PAID') {
                    return $candidate;
                }

                $paidCandidate ??= $candidate;
            }

            return $paidCandidate;
        };

        return $walk($payload);
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID' => 'paid',
            'DECLINED' => 'declined',
            'CANCELED', 'CANCELLED' => 'cancelled',
            default => 'waiting',
        };
    }
}
