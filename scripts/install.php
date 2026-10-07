<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;

Env::load($base);
$pdo = Database::connection();

function runSqlFile(\PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new \RuntimeException('Não foi possível ler ' . $file);
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function columnExists(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    runSqlFile($pdo, $base . '/database/schema.sql');

    if (!columnExists($pdo, 'users', 'cpf')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN cpf VARCHAR(14) NULL AFTER email");
    }
    if (!columnExists($pdo, 'users', 'phone')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER cpf");
    }
    if (!columnExists($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('customer','admin') NOT NULL DEFAULT 'customer' AFTER password_hash");
    }
    if (!columnExists($pdo, 'refresh_tokens', 'device_uuid')) {
        $pdo->exec("ALTER TABLE refresh_tokens ADD COLUMN device_uuid VARCHAR(128) NULL AFTER user_id");
        $pdo->exec("UPDATE refresh_tokens SET device_uuid=CONCAT('legacy-',id) WHERE device_uuid IS NULL OR device_uuid=''");
        $pdo->exec("ALTER TABLE refresh_tokens MODIFY COLUMN device_uuid VARCHAR(128) NOT NULL");
    }

    echo "Banco Caption IA atualizado com sucesso.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Falha ao atualizar o banco: " . $e->getMessage() . "\n");
    exit(1);
}
