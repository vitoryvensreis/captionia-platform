<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use CaptionIA\Services\CloudAssetCrypto;
$_ENV['APP_KEY'] = str_repeat('a', 64);
$original = "MOGRT\0SFX bytes\n" . str_repeat('A', 1024);
$encrypted = CloudAssetCrypto::encrypt($original);
if ($encrypted === $original || substr($encrypted, 0, 4) !== 'CIA1') throw new RuntimeException('Não criptografou.');
if (CloudAssetCrypto::decrypt($encrypted) !== $original) throw new RuntimeException('Roundtrip falhou.');
$encrypted[strlen($encrypted)-1] = chr(ord($encrypted[strlen($encrypted)-1]) ^ 1);
try {CloudAssetCrypto::decrypt($encrypted);throw new RuntimeException('Violação de integridade aceita.');}
catch (RuntimeException $e) {
    if ($e->getMessage() === 'Violação de integridade aceita.') throw $e;
}
echo "CloudAssetCrypto: OK\n";
