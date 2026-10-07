<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Env;
use RuntimeException;

final class PagBankService
{
    private string $baseUrl;
    private string $token;

    public function __construct(private readonly string $basePath)
    {
        $env = strtolower(Env::get('PAGBANK_ENV', 'sandbox') ?: 'sandbox');
        $this->baseUrl = $env === 'production'
            ? 'https://api.pagseguro.com'
            : 'https://sandbox.api.pagseguro.com';
        $this->token = trim(Env::get('PAGBANK_TOKEN', '') ?: '');
    }

    public function configured(): bool
    {
        return $this->token !== '';
    }

    public function checkoutMode(): string
    {
        $mode = strtolower(trim(Env::get('PAGBANK_CHECKOUT_MODE', 'hosted') ?: 'hosted'));
        return $mode === 'transparent' ? 'transparent' : 'hosted';
    }

    public function createHostedCheckout(
        array $customer,
        array $plan,
        string $referenceId,
        string $notificationUrl,
        string $returnUrl
    ): array {
        $phone = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        if (strlen($phone) !== 11 || $phone[2] !== '9') {
            throw new RuntimeException('Informe um celular brasileiro válido com DDD para continuar no PagBank.');
        }

        $expirationMinutes = max(10, min(1440, Env::int('PAGBANK_PIX_EXPIRATION_MINUTES', 30)));
        $expiration = date('Y-m-d\TH:i:sP', time() + ($expirationMinutes * 60));

        $payload = [
            'reference_id' => $referenceId,
            'expiration_date' => $expiration,
            'customer' => [
                'name' => (string) $customer['name'],
                'email' => (string) $customer['email'],
                'tax_id' => preg_replace('/\D+/', '', (string) $customer['cpf']),
                'phone' => [
                    'country' => '+55',
                    'area' => substr($phone, 0, 2),
                    'number' => substr($phone, 2),
                ],
            ],
            'customer_modifiable' => false,
            'items' => [[
                'reference_id' => (string) $plan['code'],
                'name' => (string) $plan['name'],
                'description' => $plan['code'] === 'monthly'
                    ? 'Licença Caption IA por 30 dias'
                    : 'Licença vitalícia da geração adquirida do Caption IA',
                'quantity' => 1,
                'unit_amount' => (int) $plan['price_cents'],
            ]],
            'payment_methods' => [[
                'type' => 'PIX',
            ]],
            'redirect_url' => $returnUrl,
            'redirect_waiting_time' => 5,
            'return_url' => $returnUrl,
            'notification_urls' => [$notificationUrl],
            'payment_notification_urls' => [$notificationUrl],
        ];

        $response = $this->request('POST', '/checkouts', $payload);

        if (empty($response['id'])) {
            throw new RuntimeException('PagBank não retornou o identificador do checkout.');
        }
        if ($this->hostedPayUrl($response) === null) {
            throw new RuntimeException('PagBank não retornou o link de pagamento do checkout.');
        }

        return $response;
    }

    public function getCheckout(string $checkoutId): array
    {
        return $this->request('GET', '/checkouts/' . rawurlencode($checkoutId));
    }

    public function hostedPayUrl(array $checkout): ?string
    {
        foreach (($checkout['links'] ?? []) as $link) {
            if (
                strtoupper((string) ($link['rel'] ?? '')) === 'PAY'
                && strtoupper((string) ($link['method'] ?? 'GET')) === 'GET'
            ) {
                $url = trim((string) ($link['href'] ?? ''));
                if ($this->isAllowedHostedPaymentUrl($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    public function createPixOrder(array $customer, array $plan, string $referenceId, string $notificationUrl): array
    {
        $phone = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        if (strlen($phone) < 10) {
            throw new RuntimeException('Celular inválido para gerar o PIX.');
        }

        $expirationMinutes = max(10, min(1440, Env::int('PAGBANK_PIX_EXPIRATION_MINUTES', 30)));
        $expiration = gmdate('Y-m-d\TH:i:s\Z', time() + ($expirationMinutes * 60));

        $payload = [
            'reference_id' => $referenceId,
            'customer' => [
                'name' => (string) $customer['name'],
                'email' => (string) $customer['email'],
                'tax_id' => preg_replace('/\D+/', '', (string) $customer['cpf']),
                'phones' => [[
                    'country' => '55',
                    'area' => substr($phone, 0, 2),
                    'number' => substr($phone, 2),
                    'type' => 'MOBILE',
                ]],
            ],
            'items' => [[
                'reference_id' => (string) $plan['code'],
                'name' => (string) $plan['name'],
                'quantity' => 1,
                'unit_amount' => (int) $plan['price_cents'],
            ]],
            'charges' => [[
                'reference_id' => $referenceId,
                'description' => (string) $plan['name'],
                'amount' => [
                    'value' => (int) $plan['price_cents'],
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'PIX',
                    'pix' => [
                        'expiration_date' => $expiration,
                    ],
                ],
            ]],
            'notification_urls' => [$notificationUrl],
        ];

        return $this->request('POST', '/orders', $payload, $referenceId);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/orders/' . rawurlencode($orderId));
    }

    public function fetchBinary(string $url): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('PagBank não configurado.');
        }
        if (!str_starts_with($url, $this->baseUrl . '/')) {
            throw new RuntimeException('URL de QR Code inválida.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('Não foi possível obter a imagem do QR Code.');
        }

        return ['body' => $body, 'content_type' => $type ?: 'image/png'];
    }

    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        if (!Env::bool('PAGBANK_WEBHOOK_VERIFY', true)) {
            return true;
        }
        if ($rawBody === '' || trim($signatureHeader) === '') {
            return false;
        }

        $publicKey = $this->webhookPublicKeyPem();
        if ($publicKey === '') {
            return false;
        }

        $signatures = preg_split('/\s*,\s*/', trim($signatureHeader)) ?: [];
        foreach ($signatures as $signature) {
            $decoded = base64_decode(trim($signature), true);
            if ($decoded !== false && openssl_verify($rawBody, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedHostedPaymentUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (['pagbank.com.br', 'pagseguro.com.br', 'pagseguro.uol.com.br'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function webhookPublicKeyPem(): string
    {
        $cacheDir = $this->basePath . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0770, true);
        }

        $cacheFile = $cacheDir . '/pagbank_webhook_public_key.pem';
        $hours = max(1, Env::int('PAGBANK_PUBLIC_KEY_CACHE_HOURS', 12));

        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < ($hours * 3600)) {
            return (string) file_get_contents($cacheFile);
        }

        try {
            $response = $this->request('GET', '/public-keys?type=webhook');
            $base64 = (string) ($response['public_key'] ?? '');
            if ($base64 === '') {
                return '';
            }

            $pem = "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split($base64, 64, "\n")
                . "-----END PUBLIC KEY-----\n";

            @file_put_contents($cacheFile, $pem, LOCK_EX);
            return $pem;
        } catch (\Throwable) {
            return is_file($cacheFile) ? (string) file_get_contents($cacheFile) : '';
        }
    }

    private function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('PAGBANK_TOKEN não configurado.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($idempotencyKey) {
            $headers[] = 'x-idempotency-key: ' . preg_replace('/[^A-Za-z0-9]/', '', $idempotencyKey);
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Falha de comunicação com o PagBank: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida do PagBank.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error_messages'][0]['description']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? 'Erro ao processar pagamento.';
            throw new RuntimeException('PagBank: ' . $message);
        }

        return $decoded;
    }
}
