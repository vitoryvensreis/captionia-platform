<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use CaptionIA\Core\Env;
use RuntimeException;

/** Authenticated encryption at rest, with purpose-separated APP_KEY. */
final class CloudAssetCrypto
{
    private static function key(): string
    {
        $secret = (string)Env::get('APP_KEY', '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('APP_KEY ausente ou curta demais.');
        }
        return hash_hkdf('sha256', $secret, 32, 'captionia-asset-storage-v1');
    }

    public static function encrypt(string $data): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($data, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new RuntimeException('Falha ao criptografar asset.');
        }
        return 'CIA1' . $iv . $tag . $cipher;
    }

    public static function decrypt(string $file): string
    {
        if (strlen($file) < 32 || substr($file, 0, 4) !== 'CIA1') {
            throw new RuntimeException('Arquivo de asset inválido.');
        }
        $iv = substr($file, 4, 12);
        $tag = substr($file, 16, 16);
        $plain = openssl_decrypt(substr($file, 32), 'aes-256-gcm',
            self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plain)) {
            throw new RuntimeException('Integridade ou chave de asset inválida.');
        }
        return $plain;
    }
}
