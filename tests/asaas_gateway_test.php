<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Services\AsaasService;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

// No external payment requests: exercise the auth and settlement guards only.
$_SERVER['ASAAS_ENV'] = 'sandbox';
$_SERVER['ASAAS_API_KEY'] = 'sandbox-key-test-only';
$_SERVER['ASAAS_WEBHOOK_TOKEN'] = str_repeat('Ab19', 12);
$asaas = new AsaasService();

$assert($asaas->configured(), 'Gateway should be configured with both secrets.');
$assert($asaas->verifyWebhook(str_repeat('Ab19', 12)), 'Webhook token rejected.');
$assert(!$asaas->verifyWebhook(str_repeat('Ab19', 11) . 'Ab18'), 'Wrong webhook token accepted.');
$assert(!$asaas->verifyWebhook(''), 'Empty webhook token accepted.');

foreach (['PENDING', 'OVERDUE', 'REFUNDED', 'CHARGEBACK_REQUESTED', 'AWAITING_RISK_ANALYSIS'] as $status) {
    $assert(!AsaasService::isPaymentSettled(['status' => $status]), 'Unpaid charge accepted: ' . $status);
}
$assert(AsaasService::isPaymentSettled(['status' => 'RECEIVED', 'billingType' => 'PIX']), 'Received PIX rejected.');
$assert(AsaasService::isPaymentSettled(['status' => 'CONFIRMED', 'billingType' => 'CREDIT_CARD']), 'Confirmed card rejected.');
$assert(!AsaasService::isPaymentSettled(['status' => 'CONFIRMED', 'billingType' => 'PIX']), 'Provisional PIX confirmation accepted.');
$assert(!AsaasService::isPaymentSettled(['status' => 'CONFIRMED', 'billingType' => 'BOLETO']), 'Provisional boleto confirmation accepted.');

$assert(AsaasService::validInvoiceUrl('https://www.asaas.com/i/abc'), 'Legitimate invoice URL rejected.');
foreach ([
    'http://www.asaas.com/i/abc',
    'https://asaas.com.evil.invalid/i/abc',
    'https://evil.invalid/i/abc',
    'https://www.asaas.com@evil.invalid/i/abc',
    'javascript:alert(1)',
] as $badUrl) {
    $assert(!AsaasService::validInvoiceUrl($badUrl), 'Unsafe external redirect allowed: ' . $badUrl);
}

echo "Asaas gateway security guards OK\n";
