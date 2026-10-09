<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use PDO;
use RuntimeException;

/**
 * BYOK: user's Groq secret is encrypted with an APP_KEY-derived key.
 * It is never returned via HTML/API, logs or plugin and never falls back to GROQ_API_KEY.
 */
final class UserGroqService
{
    private const MODEL = 'openai/gpt-oss-120b';
    private const WHISPER = 'whisper-large-v3';

    private static function encryptionKey(): string
    {
        $appKey = (string) Env::get('APP_KEY', '');
        if (strlen($appKey) < 32) throw new RuntimeException('Criptografia da integração Groq não configurada.');
        return hash_hkdf('sha256', $appKey, 32, 'captionia-user-groq-byok-v1');
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Não foi possível proteger a chave Groq.');
        return base64_encode('CGK1' . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $blob = base64_decode($encoded, true);
        if ($blob === false || strlen($blob) < 33 || substr($blob, 0, 4) !== 'CGK1') throw new RuntimeException('Credencial Groq inválida.');
        $plain = openssl_decrypt(substr($blob, 32), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($blob, 4, 12), substr($blob, 16, 16));
        if (!is_string($plain)) throw new RuntimeException('A chave Groq armazenada não pôde ser recuperada.');
        return $plain;
    }

    public static function validFormat(string $key): bool
    {
        return (bool) preg_match('/^gsk_[a-zA-Z0-9_-]{21,196}$/D', $key);
    }

    /** Validation always contacts Groq; a string beginning with gsk_ is not proof of access. */
    public function validate(string $key): void
    {
        if (!self::validFormat($key)) throw new RuntimeException('Informe uma chave Groq válida (gsk_...).');
        if (!extension_loaded('curl')) throw new RuntimeException('cURL indisponível para validar a integração.');
        $body = '';
        $curl = curl_init('https://api.groq.com/openai/v1/models');
        if ($curl === false) throw new RuntimeException('Não foi possível iniciar a verificação Groq.');
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 350000) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally { curl_close($curl); }
        if ($status === 401 || $status === 403) throw new RuntimeException('A Groq recusou esta chave. Verifique ou gere outra.');
        if ($status === 429) throw new RuntimeException('A Groq está limitando as verificações. Aguarde e tente novamente.');
        if ($ok === false || $status !== 200) throw new RuntimeException('Não foi possível verificar a Groq agora. Tente novamente.');
        $data = json_decode($body, true);
        $ids = [];
        foreach (($data['data'] ?? []) as $model) if (is_array($model)) $ids[(string)($model['id'] ?? '')] = true;
        if (empty($ids[self::MODEL]) || empty($ids[self::WHISPER])) {
            throw new RuntimeException('Esta conta Groq precisa permitir GPT-OSS 120B e Whisper Large V3.');
        }
    }

    public function save(int $userId, string $key): void
    {
        $key = trim($key);
        $this->validate($key);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO user_groq_credentials (user_id,key_ciphertext,key_suffix,verified_at,created_at,updated_at)
             VALUES (?,?,?,NOW(),NOW(),NOW())
             ON DUPLICATE KEY UPDATE key_ciphertext=VALUES(key_ciphertext),key_suffix=VALUES(key_suffix),verified_at=NOW(),updated_at=NOW()'
        );
        $stmt->execute([$userId, self::encrypt($key), substr($key, -4)]);
    }

    /** Never includes ciphertext or full credential. */
    public function status(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT key_suffix,verified_at,updated_at FROM user_groq_credentials WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'connected' => (bool)$data,
            'masked_key' => $data ? 'gsk_••••••••' . $data['key_suffix'] : null,
            'verified_at' => $data['verified_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
        ];
    }

    public function keyForUser(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT key_ciphertext FROM user_groq_credentials WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $encrypted = $stmt->fetchColumn();
        if (!is_string($encrypted) || $encrypted === '') throw new RuntimeException('Configure sua chave Groq em Minha conta no site Caption IA.');
        return self::decrypt($encrypted);
    }

    public function remove(int $userId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM user_groq_credentials WHERE user_id=?');
        $stmt->execute([$userId]);
    }

    /** Safe subset of upstream rate headers; never includes secrets. */
    public static function safeQuota(array $headers): array
    {
        $out = [];
        foreach (['x-ratelimit-limit-tokens','x-ratelimit-remaining-tokens','x-ratelimit-reset-tokens',
                  'x-ratelimit-limit-requests','x-ratelimit-remaining-requests','retry-after'] as $k) {
            if (isset($headers[$k]) && is_scalar($headers[$k])) {
                $val = (string)$headers[$k];
                if (strlen($val) < 40 && preg_match('/^[0-9.]+(?:m[0-9.]+s?|s)?$/iD', $val)) $out[$k] = $val;
            }
        }
        return $out;
    }
}
