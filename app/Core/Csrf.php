<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function validate(?string $token): void
    {
        $current = $_SESSION['_csrf'] ?? '';
        if (!$token || !$current || !hash_equals((string) $current, $token)) {
            throw new RuntimeException('Sua sessão expirou. Atualize a página e tente novamente.');
        }
    }
}
