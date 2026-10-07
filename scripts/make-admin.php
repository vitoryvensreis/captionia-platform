<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;

Env::load($base);

$email = mb_strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/make-admin.php email@dominio.com\n");
    exit(1);
}

$stmt = Database::connection()->prepare("UPDATE users SET role='admin',updated_at=NOW() WHERE email=?");
$stmt->execute([$email]);

if ($stmt->rowCount() < 1) {
    fwrite(STDERR, "Usuário não encontrado. Crie a conta primeiro.\n");
    exit(1);
}

echo "Admin liberado: {$email}\n";
