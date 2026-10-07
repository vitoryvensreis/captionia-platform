<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Env;

Env::load($base);

$token = trim(Env::get('PAGBANK_TOKEN', '') ?: '');
$env = strtolower(Env::get('PAGBANK_ENV', 'sandbox') ?: 'sandbox');
$url = ($env === 'production' ? 'https://api.pagseguro.com' : 'https://sandbox.api.pagseguro.com') . '/public-keys';

if ($token === '') {
    fwrite(STDERR, "PAGBANK_TOKEN não configurado no .env\n");
    exit(1);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(['type' => 'webhook']),
    CURLOPT_TIMEOUT => 30,
]);

$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
    fwrite(STDERR, "Falha ao criar chave pública de webhook. HTTP {$status} {$error}\n{$body}\n");
    exit(1);
}

echo "Chave pública de webhook criada/solicitada com sucesso.\n";
