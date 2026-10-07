<?php
declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;

Env::load($base);
$pdo = Database::connection();

$files = [
    $base . '/database/schema.sql',
    $base . '/database/migrations/20261007_upgrade.sql',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Não foi possível ler ' . $file);
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            fwrite(STDERR, "Falha em: {$statement}\n{$e->getMessage()}\n");
            exit(1);
        }
    }
}

echo "Banco Caption IA atualizado com sucesso.\n";
