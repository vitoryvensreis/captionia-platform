<?php
declare(strict_types=1);
/**
 * Usage: php scripts/import-cloud-assets.php /path/to/CaptionIA --confirm-rights
 * Must run after scripts/install.php; imports only approved media folders.
 * The public/ directory and Git repository never receive raw media.
 */
if (PHP_SAPI !== 'cli') exit(1);
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use CaptionIA\Services\CloudAssetCrypto;

if (($argv[2] ?? '') !== '--confirm-rights') {
    fwrite(STDERR, "Importação exige --confirm-rights (confirme seus direitos de distribuição).\n");
    exit(2);
}
$root = realpath($argv[1] ?? '');
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Informe o caminho para a pasta extraída CaptionIA.\n");
    exit(2);
}
Env::load($base);
$storage = $base . '/storage/cloud-assets';
if (!is_dir($storage) && !mkdir($storage, 0700, true)) {
    throw new RuntimeException('Falha ao criar diretório privado.');
}
$extMime = [
    'mogrt'=>'application/octet-stream','fsm'=>'application/octet-stream',
    'fsa'=>'application/octet-stream','aep'=>'application/octet-stream',
    'prproj'=>'application/octet-stream','epr'=>'application/octet-stream',
    'wav'=>'audio/wav','mp3'=>'audio/mpeg','m4a'=>'audio/mp4',
    'mp4'=>'video/mp4','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
    'png'=>'image/png','webp'=>'image/webp','json'=>'application/json'
];
$pdo = Database::connection();
$insert = $pdo->prepare(
    'INSERT INTO cloud_assets (id,label,category,mime,byte_size,active,created_at)
     VALUES (?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE active=1'
);
$count = 0;
$bytes = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->isLink()) continue;
    $path = $entry->getRealPath();
    if (!$path || !str_starts_with($path, rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) continue;
    $relative = str_replace('\\','/',substr($path, strlen($root)+1));
    $category = explode('/', $relative, 2)[0];
    // Copyright clearance: do NOT auto-import the commercial "TRILHAS VIRAIS" collection.
    if (!in_array($category, ['templates','guias','presets'],true)) continue;
    $extension = strtolower(pathinfo($relative,PATHINFO_EXTENSION));
    if (!isset($extMime[$extension])) continue;
    $size = $entry->getSize();
    if ($size < 1 || $size > 25*1048576) {
        fwrite(STDERR, "Ignorando arquivo fora do limite: {$relative}\n");
        continue;
    }
    $data = file_get_contents($path);
    if (!is_string($data) || strlen($data) !== $size) throw new RuntimeException("Falha ao ler {$relative}");
    $id = hash('sha256',$data);
    $destination = $storage.'/'.$id.'.bin';
    if (!is_file($destination)) {
        $tmp = $storage.'/.' . $id . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, CloudAssetCrypto::encrypt($data), LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar asset privado.');
        }
        chmod($tmp, 0600);
        if (!rename($tmp,$destination)) { @unlink($tmp);throw new RuntimeException('Falha ao publicar asset privado.'); }
    } else {
        $existing = file_get_contents($destination);
        if (!is_string($existing) || !hash_equals($id, hash('sha256',CloudAssetCrypto::decrypt($existing)))) {
            throw new RuntimeException('Asset existente não passa na verificação de integridade.');
        }
    }
    $insert->execute([$id,mb_substr(basename($relative),0,255),$category,$extMime[$extension],$size]);
    $count++; $bytes += $size;
}
echo "Importados {$count} assets / {$bytes} bytes. TRILHAS VIRAIS excluídas.\n";
