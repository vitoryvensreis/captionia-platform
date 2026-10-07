<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;

final class ApiTokenService
{
    public function issue(int $userId, string $deviceUuid): array
    {
        $access = $this->makeAccessToken($userId, $deviceUuid);
        $refresh = bin2hex(random_bytes(48));
        $hash = hash('sha256', $refresh);
        $days = max(7, Env::int('API_REFRESH_DAYS', 90));
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

        $stmt = Database::connection()->prepare(
            'INSERT INTO refresh_tokens
             (user_id,device_uuid,token_hash,expires_at,created_at)
             VALUES (?,?,?,?,NOW())'
        );
        $stmt->execute([$userId, $deviceUuid, $hash, $expiresAt]);

        return [
            'access_token' => $access,
            'expires_in' => max(300, Env::int('API_ACCESS_MINUTES', 20) * 60),
            'refresh_token' => $refresh,
            'refresh_expires_at' => $expiresAt,
        ];
    }

    public function refresh(string $refreshToken, string $deviceUuid): array
    {
        $hash = hash('sha256', trim($refreshToken));
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT * FROM refresh_tokens
             WHERE token_hash=? AND device_uuid=? AND revoked_at IS NULL AND expires_at>NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash, $deviceUuid]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Sessão inválida ou expirada.');
        }

        $pdo->prepare('UPDATE refresh_tokens SET revoked_at=NOW() WHERE id=?')->execute([$row['id']]);
        return $this->issue((int) $row['user_id'], $deviceUuid);
    }

    public function verifyAccessToken(?string $token): array
    {
        if (!$token || !str_contains($token, '.')) {
            throw new RuntimeException('Token inválido.');
        }

        [$payload64, $signature64] = explode('.', $token, 2);
        $expected = $this->b64url(hash_hmac('sha256', $payload64, $this->key(), true));

        if (!hash_equals($expected, $signature64)) {
            throw new RuntimeException('Token inválido.');
        }

        $json = $this->b64urlDecode($payload64);
        $payload = json_decode($json, true);

        if (!is_array($payload) || empty($payload['sub']) || empty($payload['exp']) || (int) $payload['exp'] < time()) {
            throw new RuntimeException('Token expirado.');
        }

        return $payload;
    }

    public function revokeRefresh(string $refreshToken): void
    {
        $hash = hash('sha256', trim($refreshToken));
        Database::connection()
            ->prepare('UPDATE refresh_tokens SET revoked_at=NOW() WHERE token_hash=? AND revoked_at IS NULL')
            ->execute([$hash]);
    }

    private function makeAccessToken(int $userId, string $deviceUuid): string
    {
        $minutes = max(5, Env::int('API_ACCESS_MINUTES', 20));
        $payload = [
            'sub' => $userId,
            'device' => $deviceUuid,
            'iat' => time(),
            'exp' => time() + ($minutes * 60),
        ];

        $payload64 = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->b64url(hash_hmac('sha256', $payload64, $this->key(), true));

        return $payload64 . '.' . $signature;
    }

    private function key(): string
    {
        $key = Env::get('APP_KEY', '') ?: '';
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY precisa ter pelo menos 32 caracteres.');
        }
        return $key;
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
