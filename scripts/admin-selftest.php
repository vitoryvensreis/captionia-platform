<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;

Env::load($base);
$pdo = Database::connection();

$requiredTables = [
    'users',
    'plans',
    'licenses',
    'license_devices',
    'checkout_orders',
    'payment_transactions',
    'webhook_events',
    'app_settings',
];

$missing = [];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $stmt->execute([$table]);
    if ((int) $stmt->fetchColumn() !== 1) {
        $missing[] = $table;
    }
}

if ($missing) {
    fwrite(STDERR, 'Tabelas ausentes: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$requiredColumns = [
    ['users', 'role'],
    ['checkout_orders', 'provider_mode'],
    ['checkout_orders', 'pagbank_checkout_id'],
    ['checkout_orders', 'hosted_checkout_url'],
    ['plans', 'description'],
    ['plans', 'featured'],
    ['plans', 'sort_order'],
    ['releases', 'sha256'],
    ['releases', 'file_size'],
    ['releases', 'notes'],
];

foreach ($requiredColumns as [$table, $column]) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() !== 1) {
        fwrite(STDERR, "Coluna ausente: {$table}.{$column}" . PHP_EOL);
        exit(1);
    }
}

$settingStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='payment_gateway' LIMIT 1");
$settingStmt->execute();
$paymentGateway = (string) ($settingStmt->fetchColumn() ?: '');

if (!in_array($paymentGateway, ['pagbank', 'bacen_pix'], true)) {
    fwrite(STDERR, "payment_gateway inválido ou ausente." . PHP_EOL);
    exit(1);
}

$planCount = (int) $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
if ($planCount < 1) {
    fwrite(STDERR, "Nenhum plano cadastrado." . PHP_EOL);
    exit(1);
}

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn(),
    'licenses' => (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE status='active' AND (expires_at IS NULL OR expires_at>NOW())")->fetchColumn(),
    'subscriptions' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.type='subscription' AND l.expires_at>NOW()")->fetchColumn(),
    'lifetime' => (int) $pdo->query("SELECT COUNT(*) FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.status='active' AND p.type='lifetime'")->fetchColumn(),
    'paid_cents' => (int) $pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payment_transactions WHERE status='paid'")->fetchColumn(),
];

$pdo->query(
    'SELECT u.id,u.name,u.email,u.status,u.created_at,
            l.id AS license_id,l.status AS license_status,l.expires_at,
            p.code AS plan_code,p.name AS plan_name
     FROM users u
     LEFT JOIN licenses l ON l.id=(
         SELECT l2.id FROM licenses l2
         WHERE l2.user_id=u.id
         ORDER BY l2.created_at DESC LIMIT 1
     )
     LEFT JOIN plans p ON p.id=l.plan_id
     ORDER BY u.created_at DESC
     LIMIT 1'
)->fetchAll();

$pdo->query(
    'SELECT co.reference_id,co.status,co.amount_cents,co.created_at,co.paid_at,
            u.name,u.email,p.name AS plan_name
     FROM checkout_orders co
     JOIN users u ON u.id=co.user_id
     JOIN plans p ON p.id=co.plan_id
     ORDER BY co.created_at DESC LIMIT 1'
)->fetchAll();

echo json_encode([
    'ok' => true,
    'panel' => 'admin',
    'stats_queries' => 'ok',
    'tables' => 'ok',
    'admins' => $stats['admins'],
    'users' => $stats['users'],
    'active_licenses' => $stats['licenses'],
    'payment_gateway' => $paymentGateway,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
