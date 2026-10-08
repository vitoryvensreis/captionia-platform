<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use RuntimeException;

final class CloudAssetService
{
    public function __construct(private readonly string $basePath) {}

    public function catalog(): array
    {
        $rows = Database::connection()->query(
            'SELECT id,label,category,mime,byte_size FROM cloud_assets
             WHERE active=1 ORDER BY category,label,id LIMIT 2000'
        )->fetchAll();
        return array_map(static fn(array $x): array => [
            'id'=>$x['id'], 'sha256'=>$x['id'], 'label'=>$x['label'],
            'category'=>$x['category'], 'mime'=>$x['mime'], 'bytes'=>(int)$x['byte_size']
        ], $rows);
    }

    public function fetch(string $assetId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $assetId)) {
            throw new RuntimeException('Identificador de asset inválido.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,label,category,mime,byte_size FROM cloud_assets WHERE id=? AND active=1 LIMIT 1'
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Asset indisponível.');
        $root = realpath($this->basePath . '/storage/cloud-assets');
        $file = realpath($this->basePath . '/storage/cloud-assets/' . $assetId . '.bin');
        if (!$root || !$file || !str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Arquivo privado indisponível.');
        }
        if (!is_file($file) || filesize($file) > 26214400 + 32) {
            throw new RuntimeException('Arquivo privado inválido.');
        }
        $crypted = file_get_contents($file);
        if (!is_string($crypted)) throw new RuntimeException('Falha ao ler asset.');
        $plain = CloudAssetCrypto::decrypt($crypted);
        if (strlen($plain) !== (int)$row['byte_size'] || !hash_equals($row['id'],hash('sha256',$plain))) {
            throw new RuntimeException('Integridade do asset inválida.');
        }
        return ['data'=>$plain, 'mime'=>$row['mime'], 'bytes'=>strlen($plain)];
    }
}
