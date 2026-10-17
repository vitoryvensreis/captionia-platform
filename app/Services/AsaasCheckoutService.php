<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;
use Throwable;

/** Creates Asaas charges, verifies payments server-to-server and grants licenses idempotently. */
final class AsaasCheckoutService
{
    public function __construct(
        private readonly AsaasService $asaas,
        private readonly LicenseService $licenses
    ) {
    }

    public function createForUser(array $user, array $plan): array
    {
        if (!$this->asaas->configured()) {
            throw new RuntimeException('O Asaas ainda não está configurado (API e webhook).');
        }
        if ((int) ($plan['price_cents'] ?? 0) < 100) {
            throw new RuntimeException('O plano precisa ter valor válido para cobrança.');
        }
        $pdo = Database::connection();
        $customerId = trim((string) ($user['asaas_customer_id'] ?? ''));
        if ($customerId === '') {
            $lookup = $pdo->prepare('SELECT asaas_customer_id FROM users WHERE id=? LIMIT 1');
            $lookup->execute([$user['id']]);
            $customerId = trim((string) $lookup->fetchColumn());
        }
        if ($customerId === '') {
            $customerId = $this->asaas->customer($user);
            $pdo->prepare('UPDATE users SET asaas_customer_id=? WHERE id=? AND asaas_customer_id IS NULL')
                ->execute([$customerId, $user['id']]);
        }

        $reference = 'CIA-' . strtoupper(bin2hex(random_bytes(8)));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400 * 7);
        $recurring = ($plan['type'] ?? '') === 'subscription';
        $mode = $recurring ? 'asaas_subscription' : 'asaas_payment';
        $pdo->prepare(
            'INSERT INTO checkout_orders
             (reference_id,user_id,plan_id,status,amount_cents,provider_mode,
              asaas_customer_id,expires_at,created_at,updated_at)
             VALUES (?,?,?,"creating",?,?,?, ?,NOW(),NOW())'
        )->execute([
            $reference, $user['id'], $plan['id'], $plan['price_cents'],
            $mode, $customerId, $expiresAt,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        try {
            if ($recurring) {
                $subscription = $this->asaas->createSubscription($customerId, $plan, $reference);
                $subscriptionId = (string) ($subscription['id'] ?? '');
                if ($subscriptionId === '') {
                    throw new RuntimeException('Asaas não retornou ID da assinatura.');
                }
                $pdo->prepare(
                    'UPDATE checkout_orders
                     SET asaas_subscription_id=?,status="waiting",raw_response_json=?,updated_at=NOW()
                     WHERE id=?'
                )->execute([$subscriptionId, json_encode($subscription, JSON_THROW_ON_ERROR), $orderId]);
                // Asaas may generate the first charge asynchronously.
                try {
                    $this->discoverFirstSubscriptionPayment($orderId, $subscriptionId);
                } catch (Throwable $e) {
                    error_log('[CaptionIA Asaas] first charge lookup deferred: ' . $e->getMessage());
                }
            } else {
                $payment = $this->asaas->createPayment($customerId, $plan, $reference);
                $this->linkFirstPayment($orderId, $payment);
            }
        } catch (Throwable $e) {
            // An upstream timeout might still have created a charge. Retain the
            // local reference for reconciliation; do not automatically retry POST.
            $pdo->prepare('UPDATE checkout_orders SET status="failed",updated_at=NOW() WHERE id=? AND status="creating"')
                ->execute([$orderId]);
            throw $e;
        }
        return $this->getOrder($reference)
            ?? throw new RuntimeException('Cobrança criada, mas pedido não encontrado.');
    }

    public function sync(array $order): array
    {
        if (!str_starts_with((string) ($order['provider_mode'] ?? ''), 'asaas_')
            || ($order['status'] ?? '') === 'paid'
            || !$this->asaas->configured()) {
            return $order;
        }
        // Browser polling is only a fallback to the webhook. Limit upstream queries.
        $lastCheck = strtotime((string) ($order['updated_at'] ?? '')) ?: 0;
        if (time() - $lastCheck < 30) {
            return $order;
        }
        Database::connection()->prepare('UPDATE checkout_orders SET updated_at=NOW() WHERE id=?')
            ->execute([(int) $order['id']]);
        try {
            $paymentId = (string) ($order['asaas_payment_id'] ?? '');
            if ($paymentId === '' && !empty($order['asaas_subscription_id'])) {
                $this->discoverFirstSubscriptionPayment((int) $order['id'], (string) $order['asaas_subscription_id']);
                $order = $this->getOrder((string) $order['reference_id']) ?? $order;
                $paymentId = (string) ($order['asaas_payment_id'] ?? '');
            }
            if ($paymentId !== '') {
                $payment = $this->asaas->payment($paymentId);
                if (AsaasService::isPaymentSettled($payment)) {
                    $this->applyVerifiedPayment($payment);
                } else {
                    $this->refreshUnpaidPayment($order, $payment);
                }
            }
        } catch (Throwable $e) {
            error_log('[CaptionIA Asaas] status consultation deferred: ' . $e->getMessage());
        }
        return $this->getOrder((string) $order['reference_id']) ?? $order;
    }

    public function processWebhook(string $rawBody, string $webhookToken): array
    {
        if (!$this->asaas->verifyWebhook($webhookToken)) {
            throw new RuntimeException('Token do webhook Asaas inválido.');
        }
        if (strlen($rawBody) > 262144) {
            throw new RuntimeException('Payload do webhook Asaas excedeu o limite.');
        }
        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['event'] ?? null)) {
            throw new RuntimeException('Evento Asaas inválido.');
        }
        $eventId = (string) $event['id'];
        $eventName = (string) $event['event'];
        $hash = hash('sha256', $eventId);
        $pdo = Database::connection();
        $previous = $pdo->prepare(
            'SELECT status FROM webhook_events WHERE provider="asaas" AND event_hash=? LIMIT 1'
        );
        $previous->execute([$hash]);
        if ($previous->fetchColumn() === 'processed') {
            return ['duplicate' => true, 'event' => $eventName];
        }
        $pdo->prepare(
            'INSERT INTO webhook_events (provider,event_hash,status,payload_json,created_at)
             VALUES ("asaas",?,"received",?,NOW())
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)'
        )->execute([$hash, $rawBody]);

        try {
            $paidEvents = ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'];
            $warningEvents = ['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED',
                              'PAYMENT_CHARGEBACK_DISPUTE', 'PAYMENT_DELETED'];
            $order = null;
            if (in_array($eventName, $paidEvents, true)) {
                $paymentId = (string) ($event['payment']['id'] ?? '');
                if ($paymentId === '') {
                    throw new RuntimeException('Confirmação Asaas sem ID de pagamento.');
                }
                // Never trust a webhook's declared amount or state in isolation.
                $remote = $this->asaas->payment($paymentId);
                if (!AsaasService::isPaymentSettled($remote)) {
                    throw new RuntimeException('Cobrança ainda não foi confirmada na API Asaas.');
                }
                $order = $this->applyVerifiedPayment($remote);
            } elseif ($eventName === 'PAYMENT_CREATED') {
                // No access until the payment is actually settled.
                $this->identifyPayment($event['payment'] ?? [], false);
            } elseif (in_array($eventName, $warningEvents, true)) {
                $pdo->prepare(
                    'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
                     VALUES (NULL,"payment.asaas_review_required",?,NOW())'
                )->execute([json_encode([
                    'event' => $eventName,
                    'payment_id' => $event['payment']['id'] ?? null,
                    'event_id' => $eventId,
                ], JSON_THROW_ON_ERROR)]);
                // Refund/chargeback access requires separate entitlement reconciliation.
                // Do not revoke unrelated plans or other valid paid periods automatically.
            }
            $pdo->prepare(
                'UPDATE webhook_events SET status="processed",processed_at=NOW(),error_message=NULL
                 WHERE provider="asaas" AND event_hash=?'
            )->execute([$hash]);
            return ['duplicate' => false, 'event' => $eventName, 'order' => $order];
        } catch (Throwable $e) {
            $pdo->prepare(
                'UPDATE webhook_events SET status="failed",processed_at=NOW(),error_message=?
                 WHERE provider="asaas" AND event_hash=?'
            )->execute([mb_substr($e->getMessage(), 0, 500), $hash]);
            throw $e;
        }
    }

    /** Only server-verified CONFIRMED or RECEIVED charges can activate licenses. */
    public function applyVerifiedPayment(array $payment): ?array
    {
        if (!AsaasService::isPaymentSettled($payment)) {
            throw new RuntimeException('Cobrança Asaas não foi paga.');
        }
        $order = $this->identifyPayment($payment, true);
        if (!$order) {
            return null; // unrelated Asaas transaction; no authorization granted
        }
        $paymentId = (string) ($payment['id'] ?? '');
        $pdo = Database::connection();
        if ((int) round(((float) ($payment['value'] ?? 0)) * 100) !== (int) $order['amount_cents']) {
            throw new RuntimeException('Valor da cobrança Asaas diverge do pedido.');
        }
        if ((string) ($payment['customer'] ?? '') !== (string) ($order['asaas_customer_id'] ?? '')) {
            throw new RuntimeException('Cliente da cobrança Asaas não corresponde ao pedido.');
        }
        $expectedSubscription = (string) ($order['asaas_subscription_id'] ?? '');
        if ($expectedSubscription !== '' && (string) ($payment['subscription'] ?? '') !== $expectedSubscription) {
            throw new RuntimeException('Assinatura Asaas não corresponde ao pedido.');
        }
        if ($expectedSubscription === '' && !empty($payment['subscription'])) {
            throw new RuntimeException('Cobrança recorrente indevidamente atribuída ao pedido avulso.');
        }

        $pdo->prepare(
            'UPDATE checkout_orders SET status="paid",asaas_payment_id=?,
             hosted_checkout_url=COALESCE(NULLIF(?, ""),hosted_checkout_url),
             paid_at=COALESCE(paid_at,NOW()),updated_at=NOW()
             WHERE id=? AND (asaas_payment_id IS NULL OR asaas_payment_id=?)'
        )->execute([$paymentId, $this->invoiceUrl($payment), $order['id'], $paymentId]);
        $pdo->prepare(
            'INSERT INTO payment_transactions
             (user_id,provider,provider_payment_id,amount_cents,status,payload_json,created_at)
             VALUES (?,"asaas",?,?,"paid",?,NOW())
             ON DUPLICATE KEY UPDATE status="paid"'
        )->execute([
            $order['user_id'], $paymentId, $order['amount_cents'],
            json_encode(['id' => $paymentId, 'status' => $payment['status']], JSON_THROW_ON_ERROR),
        ]);
        $fresh = $this->getOrder((string) $order['reference_id']);
        if ($fresh) {
            $this->licenses->activatePaidOrder($fresh);
        }
        return $this->getOrder((string) $order['reference_id']);
    }

    private function discoverFirstSubscriptionPayment(int $orderId, string $subscriptionId): void
    {
        $payments = $this->asaas->subscriptionPayments($subscriptionId);
        if (!$payments) {
            return;
        }
        usort($payments, static fn($a, $b) => strcmp((string) ($a['dateCreated'] ?? ''),
                                                    (string) ($b['dateCreated'] ?? '')));
        $this->linkFirstPayment($orderId, $payments[0]);
    }

    private function linkFirstPayment(int $orderId, array $payment): void
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            throw new RuntimeException('Asaas não informou ID da cobrança.');
        }
        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE checkout_orders
             SET asaas_payment_id=?,hosted_checkout_url=?,status="waiting",
                 raw_response_json=?,updated_at=NOW()
             WHERE id=? AND (asaas_payment_id IS NULL OR asaas_payment_id=?) AND status!="paid"'
        )->execute([
            $paymentId, $this->invoiceUrl($payment),
            json_encode($payment, JSON_THROW_ON_ERROR), $orderId, $paymentId,
        ]);
    }

    private function refreshUnpaidPayment(array $order, array $payment): void
    {
        $url = $this->invoiceUrl($payment);
        if ($url !== '') {
            Database::connection()->prepare(
                'UPDATE checkout_orders SET hosted_checkout_url=?,updated_at=NOW() WHERE id=? AND status!="paid"'
            )->execute([$url, $order['id']]);
        }
    }

    /**
     * Bind verified Asaas payment to an existing local order, or create a
     * separate checkout order for a renewal (one payment -> one license period).
     */
    private function identifyPayment(array $payment, bool $allowRenewal): ?array
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            return null;
        }
        $pdo = Database::connection();
        $found = $pdo->prepare(
            'SELECT reference_id FROM checkout_orders WHERE asaas_payment_id=? LIMIT 1'
        );
        $found->execute([$paymentId]);
        $ref = $found->fetchColumn();
        if ($ref) {
            return $this->getOrder((string) $ref);
        }

        $subscriptionId = (string) ($payment['subscription'] ?? '');
        if ($subscriptionId !== '') {
            $parent = $pdo->prepare(
                'SELECT * FROM checkout_orders
                 WHERE asaas_subscription_id=? AND provider_mode="asaas_subscription"
                 ORDER BY id ASC LIMIT 1'
            );
            $parent->execute([$subscriptionId]);
            $initial = $parent->fetch();
            if (!$initial) {
                return null;
            }
            if ((string) ($payment['customer'] ?? '') !== (string) $initial['asaas_customer_id']) {
                throw new RuntimeException('Cliente da assinatura Asaas divergente.');
            }
            if (empty($initial['asaas_payment_id'])) {
                $this->linkFirstPayment((int) $initial['id'], $payment);
                return $this->getOrder((string) $initial['reference_id']);
            }
            if (!$allowRenewal) {
                return null;
            }
            $reference = 'CIA-' . strtoupper(bin2hex(random_bytes(8)));
            $pdo->prepare(
                'INSERT INTO checkout_orders
                 (reference_id,user_id,plan_id,status,amount_cents,provider_mode,
                  asaas_customer_id,asaas_subscription_id,asaas_payment_id,
                  hosted_checkout_url,expires_at,created_at,updated_at)
                 VALUES (?,?,?,"waiting",?,"asaas_subscription",?,?,?,?,?,NOW(),NOW())'
            )->execute([
                $reference, $initial['user_id'], $initial['plan_id'], $initial['amount_cents'],
                $initial['asaas_customer_id'], $subscriptionId, $paymentId,
                $this->invoiceUrl($payment), date('Y-m-d H:i:s', time() + 86400 * 7),
            ]);
            return $this->getOrder($reference);
        }

        $reference = (string) ($payment['externalReference'] ?? '');
        if (!preg_match('/^CIA-[A-F0-9]{16}$/', $reference)) {
            return null;
        }
        $order = $this->getOrder($reference);
        if (!$order || ($order['provider_mode'] ?? '') !== 'asaas_payment') {
            return null;
        }
        if (!empty($order['asaas_payment_id']) && (string) $order['asaas_payment_id'] !== $paymentId) {
            throw new RuntimeException('Cobrança Asaas diferente da cobrança original.');
        }
        if (empty($order['asaas_payment_id'])) {
            $this->linkFirstPayment((int) $order['id'], $payment);
        }
        return $this->getOrder($reference);
    }

    private function invoiceUrl(array $payment): string
    {
        $url = trim((string) ($payment['invoiceUrl'] ?? ''));
        return AsaasService::validInvoiceUrl($url) ? $url : '';
    }

    private function getOrder(string $reference): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT co.*,p.code AS plan_code,p.name AS plan_name,p.type AS plan_type,
                    p.includes_updates,p.max_devices,
                    u.name AS customer_name,u.email AS customer_email,u.cpf,u.phone
             FROM checkout_orders co
             JOIN plans p ON p.id=co.plan_id
             JOIN users u ON u.id=co.user_id
             WHERE co.reference_id=? LIMIT 1'
        );
        $stmt->execute([$reference]);
        return $stmt->fetch() ?: null;
    }
}
