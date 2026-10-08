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
    if (!columnExists($pdo, 'plans', 'description')) {
        $pdo->exec("ALTER TABLE plans ADD COLUMN description VARCHAR(255) NULL AFTER name");
    }
    if (!columnExists($pdo, 'plans', 'featured')) {
        $pdo->exec("ALTER TABLE plans ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER includes_updates");
    }
    if (!columnExists($pdo, 'plans', 'sort_order')) {
        $pdo->exec("ALTER TABLE plans ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER featured");
    }

    if (!columnExists($pdo, 'refresh_tokens', 'device_uuid')) {
        $pdo->exec("ALTER TABLE refresh_tokens ADD COLUMN device_uuid VARCHAR(128) NULL AFTER user_id");
        $pdo->exec("UPDATE refresh_tokens SET device_uuid=CONCAT('legacy-',id) WHERE device_uuid IS NULL OR device_uuid=''");
        $pdo->exec("ALTER TABLE refresh_tokens MODIFY COLUMN device_uuid VARCHAR(128) NOT NULL");
    }

    if (!columnExists($pdo, 'releases', 'sha256')) {
        $pdo->exec("ALTER TABLE releases ADD COLUMN sha256 CHAR(64) NULL AFTER file_path");
    }
    if (!columnExists($pdo, 'releases', 'file_size')) {
        $pdo->exec("ALTER TABLE releases ADD COLUMN file_size BIGINT UNSIGNED NULL AFTER sha256");
    }
    if (!columnExists($pdo, 'releases', 'notes')) {
        $pdo->exec("ALTER TABLE releases ADD COLUMN notes TEXT NULL AFTER file_size");
    }

    if (!columnExists($pdo, 'checkout_orders', 'provider_mode')) {
        $pdo->exec("ALTER TABLE checkout_orders ADD COLUMN provider_mode VARCHAR(32) NOT NULL DEFAULT 'transparent_pix' AFTER amount_cents");
    }
    if (!columnExists($pdo, 'checkout_orders', 'pagbank_checkout_id')) {
        $pdo->exec("ALTER TABLE checkout_orders ADD COLUMN pagbank_checkout_id VARCHAR(190) NULL AFTER pagbank_order_id");
        $pdo->exec("CREATE UNIQUE INDEX uq_checkout_pagbank_checkout_id ON checkout_orders (pagbank_checkout_id)");
    }
    if (!columnExists($pdo, 'checkout_orders', 'hosted_checkout_url')) {
        $pdo->exec("ALTER TABLE checkout_orders ADD COLUMN hosted_checkout_url TEXT NULL AFTER pagbank_charge_id");
    }

    $adminEmail = mb_strtolower(trim((string) \CaptionIA\Core\Env::get('ADMIN_EMAIL', '')));
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("UPDATE users SET role='admin',updated_at=NOW() WHERE email=?");
        $stmt->execute([$adminEmail]);
    }

    echo "Banco Caption IA atualizado com sucesso.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Falha ao atualizar o banco: " . $e->getMessage() . "\n");
    exit(1);
}
