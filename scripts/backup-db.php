<?php
declare(strict_types=1);

$basePath = $argv[1] ?? '';
$backupDir = $argv[2] ?? '';

if ($basePath === '' || $backupDir === '') {
    fwrite(STDERR, "Uso: php backup-db.php /caminho/app /caminho/backups\n");
    exit(1);
}

$envFile = rtrim($basePath, '/') . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env não encontrado em {$basePath}\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    if (
        (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
        (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
        $value = substr($value, 1, -1);
    }

    $env[$key] = $value;
}

$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$database = $env['DB_DATABASE'] ?? '';
$username = $env['DB_USERNAME'] ?? '';
$password = $env['DB_PASSWORD'] ?? '';

if ($database === '' || $username === '') {
    fwrite(STDERR, "DB_DATABASE/DB_USERNAME não configurados no .env\n");
    exit(1);
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Não foi possível criar {$backupDir}\n");
    exit(1);
}

@chmod($backupDir, 0700);

$stamp = date('Ymd-His');
$backupFile = rtrim($backupDir, '/') . "/{$database}-{$stamp}.sql";
$defaultsFile = tempnam(sys_get_temp_dir(), 'captionia-mysql-');

if ($defaultsFile === false) {
    fwrite(STDERR, "Não foi possível criar arquivo temporário de credenciais.\n");
    exit(1);
}

$ini = "[client]\n"
    . "host=" . str_replace(["\r", "\n"], '', $host) . "\n"
    . "port=" . str_replace(["\r", "\n"], '', $port) . "\n"
    . "user=" . str_replace(["\r", "\n"], '', $username) . "\n"
    . "password=" . str_replace(["\r", "\n"], '', $password) . "\n";

file_put_contents($defaultsFile, $ini, LOCK_EX);
@chmod($defaultsFile, 0600);

$command = [
    'mysqldump',
    '--defaults-extra-file=' . $defaultsFile,
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--no-tablespaces',
    '--default-character-set=utf8mb4',
    $database,
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $backupFile, 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    @unlink($defaultsFile);
    fwrite(STDERR, "Não foi possível iniciar mysqldump.\n");
    exit(1);
}

fclose($pipes[0]);
$error = stream_get_contents($pipes[2]) ?: '';
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($defaultsFile);

if ($exitCode !== 0 || !is_file($backupFile) || filesize($backupFile) === 0) {
    @unlink($backupFile);
    fwrite(STDERR, "Backup MySQL falhou: {$error}\n");
    exit(1);
}

@chmod($backupFile, 0600);

$cutoff = time() - (14 * 86400);
foreach (glob(rtrim($backupDir, '/') . '/*.sql') ?: [] as $oldFile) {
    if (is_file($oldFile) && filemtime($oldFile) < $cutoff) {
        @unlink($oldFile);
    }
}

echo $backupFile . PHP_EOL;
