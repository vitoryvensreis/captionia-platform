<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CaptionIA\Services\InstallerService;

function assertCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'captionia-pe-test-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Falha ao criar diretório temporário para teste.');
}
try {
    $valid = $dir . '/valid.exe';
    $invalid = $dir . '/invalid.exe';
    $fake = str_repeat("\0", 256);
    $fake = 'MZ' . substr($fake, 2);
    $fake = substr_replace($fake, pack('V', 128), 0x3c, 4);
    $fake = substr_replace($fake, "PE\0\0", 128, 4);
    file_put_contents($valid, $fake);
    InstallerService::validateExecutableHeader($valid);

    $invalidCases = ['not an exe', str_repeat('M', 256), 'MZ' . str_repeat("\0", 254)];
    foreach ($invalidCases as $case) {
        file_put_contents($invalid, $case);
        try {
            InstallerService::validateExecutableHeader($invalid);
            throw new RuntimeException('Arquivo inválido foi aceito.');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Arquivo inválido foi aceito.') {
                throw $e;
            }
        }
    }
    // EXE and ZIP must be distinct: updater contract continues returning ZIP only.
    $app = file_get_contents(dirname(__DIR__) . '/app/Core/App.php');
    $form = file_get_contents(dirname(__DIR__) . '/views/admin/dashboard.php');
    $account = file_get_contents(dirname(__DIR__) . '/views/account.php');
    assertCheck(str_contains($app, "'Content-Type: application/zip'"), 'ZIP API foi alterada.');
    assertCheck(str_contains($app, "downloadCustomerInstaller"), 'Download EXE ausente.');
    assertCheck(str_contains($app, "X-Content-Type-Options: nosniff"), 'Proteção do download ausente.');
    assertCheck(str_contains($form, 'name="release_exe"'), 'Upload EXE não está disponível no Admin.');
    assertCheck(str_contains($account, '/minha-conta/baixar-exe/'), 'Cliente não recebe o download EXE.');

    echo "PASS: PE header, EXE upload/download and ZIP updater isolation\n";
} finally {
    @unlink($dir . '/valid.exe');
    @unlink($dir . '/invalid.exe');
    @rmdir($dir);
}
