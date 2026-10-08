<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use PDO;
use RuntimeException;
use ZipArchive;

final class ReleaseService
{
    private const MAX_UPLOAD_BYTES = 536870912; // 512 MiB

    public function __construct(private readonly string $basePath)
    {
    }

    public function latestActive(): ?array
    {
        $stmt = Database::connection()->query(
            'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
             FROM releases
             WHERE active=1
             ORDER BY published_at DESC,id DESC
             LIMIT 1'
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(): array
    {
        return Database::connection()
            ->query(
                'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
                 FROM releases
                 ORDER BY published_at DESC,id DESC'
            )
            ->fetchAll();
    }

    public function findActiveByVersion(string $version): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
             FROM releases
             WHERE version=? AND active=1
             LIMIT 1'
        );
        $stmt->execute([$version]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function currentVersionInfo(): array
    {
        $release = $this->latestActive();
        if ($release) {
            return [
                'version' => (string) $release['version'],
                'major' => (int) $release['major_version'],
            ];
        }

        $version = Env::get('CAPTIONIA_CURRENT_VERSION', '3.3.0') ?: '3.3.0';
        return [
            'version' => $version,
            'major' => max(1, Env::int('CAPTIONIA_CURRENT_MAJOR', (int) explode('.', ltrim($version, 'vV'))[0])),
        ];
    }

    public function publishUploaded(array $file, string $version, string $notes = ''): array
    {
        $version = trim($version);
        $notes = trim($notes);

        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('Versão inválida. Use o formato 3.4.0.');
        }

        $major = (int) explode('.', $version)[0];
        if ($major < 1) {
            throw new RuntimeException('Versão principal inválida.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $name = (string) ($file['name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('O arquivo enviado não é um upload válido.');
        }
        if ($size < 1024 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('O ZIP deve ter entre 1 KB e 512 MB.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Envie o ZIP oficial do Caption IA.');
        }

        $this->validatePackage($tmp, $version);

        $dir = $this->releaseDir();
        $safeVersion = preg_replace('/[^0-9A-Za-z._+-]/', '-', $version) ?: $version;
        $fileName = 'CaptionIA-' . $safeVersion . '.zip';
        $absolute = $dir . DIRECTORY_SEPARATOR . $fileName;
        $relative = 'storage/releases/' . $fileName;
        $staging = $absolute . '.upload-' . bin2hex(random_bytes(5));

        if (!move_uploaded_file($tmp, $staging)) {
            throw new RuntimeException('Não foi possível armazenar o pacote enviado.');
        }

        $sha256 = hash_file('sha256', $staging);
        $storedSize = filesize($staging);
        if (!is_string($sha256) || strlen($sha256) !== 64 || $storedSize === false) {
            @unlink($staging);
            throw new RuntimeException('Não foi possível calcular a integridade do pacote.');
        }

        if (!@rename($staging, $absolute)) {
            @unlink($staging);
            throw new RuntimeException('Não foi possível publicar o pacote da atualização.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO releases
                 (version,major_version,file_path,sha256,file_size,notes,published_at,active)
                 VALUES (?,?,?,?,?,?,NOW(),1)
                 ON DUPLICATE KEY UPDATE
                   major_version=VALUES(major_version),
                   file_path=VALUES(file_path),
                   sha256=VALUES(sha256),
                   file_size=VALUES(file_size),
                   notes=VALUES(notes),
                   published_at=NOW(),
                   active=1'
            );
            $stmt->execute([
                $version,
                $major,
                $relative,
                $sha256,
                (int) $storedSize,
                $notes !== '' ? mb_substr($notes, 0, 4000) : null,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->findActiveByVersion($version)
            ?? throw new RuntimeException('Release publicada, mas não foi possível relê-la.');
    }

    public function setActive(int $releaseId, bool $active): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE releases SET active=? WHERE id=?'
        );
        $stmt->execute([$active ? 1 : 0, $releaseId]);

        if ($stmt->rowCount() < 1) {
            $exists = Database::connection()->prepare('SELECT id FROM releases WHERE id=?');
            $exists->execute([$releaseId]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Release não encontrada.');
            }
        }
    }

    public function absolutePath(array $release): string
    {
        $relative = str_replace('\\', '/', ltrim((string) ($release['file_path'] ?? ''), '/'));
        if (!str_starts_with($relative, 'storage/releases/')) {
            throw new RuntimeException('Caminho do pacote inválido.');
        }

        $root = realpath($this->releaseDir());
        $file = realpath($this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if ($root === false || $file === false || !is_file($file)) {
            throw new RuntimeException('Pacote da atualização não encontrado no servidor.');
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($file, $rootPrefix)) {
            throw new RuntimeException('Pacote fora do diretório autorizado.');
        }

        return $file;
    }

    private function releaseDir(): string
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar storage/releases.');
        }
        return $dir;
    }

    private function validatePackage(string $zipPath, string $version): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extensão PHP zip é obrigatória para validar e publicar um instalador.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('O arquivo enviado não é um ZIP válido.');
        }

        try {
            $required = [
                'CaptionIA/index.html',
                'CaptionIA/js/main.js',
                'CaptionIA/CSXS/manifest.xml',
            ];

            foreach ($required as $entry) {
                if ($zip->locateName($entry, ZipArchive::FL_NOCASE) === false) {
                    throw new RuntimeException('ZIP inválido: ausente ' . $entry . '.');
                }
            }

            $manifest = $zip->getFromName('CaptionIA/CSXS/manifest.xml');
            if (!is_string($manifest)) {
                throw new RuntimeException('Não foi possível validar o manifest do ZIP.');
            }

            if (
                !preg_match('/ExtensionBundleVersion="([^"]+)"/', $manifest, $m)
                || (string) $m[1] !== $version
            ) {
                throw new RuntimeException('A versão informada não corresponde ao manifest do ZIP.');
            }
        } finally {
            $zip->close();
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'O ZIP ultrapassa o limite de upload do servidor. Aumente upload_max_filesize/post_max_size ou envie um pacote menor.',
            UPLOAD_ERR_PARTIAL => 'O upload da atualização foi interrompido.',
            UPLOAD_ERR_NO_FILE => 'Selecione o ZIP da atualização.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor está sem diretório temporário para uploads.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o upload.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP bloqueou o upload.',
            default => 'Falha no upload da atualização (código ' . $error . ').',
        };
    }
}
