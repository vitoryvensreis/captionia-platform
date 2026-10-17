<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Env;
use RuntimeException;

/**
 * Asaas v3 HTTP client. The API key is server-side only; never placed in a view,
 * JavaScript, source control, logs or the webhook authentication token.
 */
final class AsaasService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $env = strtolower(trim(Env::get('ASAAS_ENV', 'sandbox') ?: 'sandbox'));
        if (!in_array($env, ['sandbox', 'production'], true)) {
            throw new RuntimeException('ASAAS_ENV deve ser sandbox ou production.');
        }
        $this->baseUrl = $env === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';
        $this->apiKey = trim(Env::get('ASAAS_API_KEY', '') ?: '');
    }

    public function configured(): bool
    {
        return $this->apiKey !== '' && trim(Env::get('ASAAS_WEBHOOK_TOKEN', '') ?: '') !== '';
    }

    public function verifyWebhook(string $token): bool
    {
        $expected = trim(Env::get('ASAAS_WEBHOOK_TOKEN', '') ?: '');
        return strlen($expected) >= 32 && $token !== '' && hash_equals($expected, $token);
    }

    public function customer(array $user): string
    {
        $externalRef = 'captionia-user-' . (int) $user['id'];
        $existing = $this->request('GET', '/customers?externalReference=' . rawurlencode($externalRef) . '&limit=10');
        foreach ($existing['data'] ?? [] as $item) {
            if (($item['externalReference'] ?? '') === $externalRef && !empty($item['id'])) {
                return (string) $item['id'];
            }
        }
        $cpf = preg_replace('/\D+/', '', (string) ($user['cpf'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($user['phone'] ?? ''));
        $data = [
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'cpfCnpj' => $cpf,
            'externalReference' => $externalRef,
        ];
        if ($phone !== '') {
            $data['mobilePhone'] = $phone;
        }
        $response = $this->request('POST', '/customers', $data);
        $id = (string) ($response['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Asaas não retornou o identificador do cliente.');
        }
        return $id;
    }

    public function createPayment(string $customerId, array $plan, string $reference): array
    {
        return $this->request('POST', '/payments', [
            'customer' => $customerId,
            'billingType' => 'UNDEFINED',
            'value' => round(((int) $plan['price_cents']) / 100, 2),
            'dueDate' => date('Y-m-d'),
            'description' => mb_substr('Caption IA - ' . (string) $plan['name'], 0, 500),
            'externalReference' => $reference,
        ]);
    }

    public function createSubscription(string $customerId, array $plan, string $reference): array
    {
        $cycle = match ((string) $plan['billing_interval']) {
            'month' => 'MONTHLY',
            'year' => 'YEARLY',
            default => throw new RuntimeException('Plano recorrente precisa ser mensal ou anual.'),
        };
        return $this->request('POST', '/subscriptions', [
            'customer' => $customerId,
            'billingType' => 'UNDEFINED',
            'value' => round(((int) $plan['price_cents']) / 100, 2),
            'nextDueDate' => date('Y-m-d'),
            'cycle' => $cycle,
            'description' => mb_substr('Caption IA - ' . (string) $plan['name'], 0, 500),
            'externalReference' => $reference,
        ]);
    }

    public function subscriptionPayments(string $id): array
    {
        $result = $this->request('GET', '/subscriptions/' . rawurlencode($id) . '/payments?limit=20');
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function payment(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    public function pixQrCode(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($paymentId) . '/pixQrCode');
    }

    public static function isPaymentSettled(array $payment): bool
    {
        $status = strtoupper((string) ($payment['status'] ?? ''));
        $type = strtoupper((string) ($payment['billingType'] ?? ''));
        // Pix may be CONFIRMED during a precautionary hold. Wait for RECEIVED.
        return $status === 'RECEIVED'
            || ($status === 'CONFIRMED' && $type === 'CREDIT_CARD');
    }

    public static function validInvoiceUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'asaas.com' || str_ends_with($host, '.asaas.com'));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('ASAAS_API_KEY não configurada no servidor.');
        }
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new RuntimeException('Não foi possível iniciar a conexão Asaas.');
        }
        $headers = [
            'access_token: ' . $this->apiKey,
            'accept: application/json',
            'content-type: application/json',
            'User-Agent: CaptionIA-Platform/1.0',
        ];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException('Falha de comunicação com Asaas: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida da API Asaas (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            // Do not include full provider responses; they can contain sensitive data.
            $message = (string) ($decoded['errors'][0]['description'] ?? $decoded['message'] ?? 'Erro no processamento.');
            throw new RuntimeException('Asaas (HTTP ' . $status . '): ' . mb_substr($message, 0, 220));
        }
        return $decoded;
    }
}
