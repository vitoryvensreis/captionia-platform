<?php
declare(strict_types=1);

namespace CaptionIA\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        try {
            self::$pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    Env::get('DB_HOST', '127.0.0.1'),
                    Env::get('DB_PORT', '3306'),
                    Env::get('DB_DATABASE', 'caption')
                ),
                Env::get('DB_USERNAME', 'captionuser'),
                Env::get('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao conectar ao banco de dados.', 0, $e);
        }

        return self::$pdo;
    }
}
