<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use PDO;
use RuntimeException;

final class InstallerService
{
    private const MAX_UPLOAD_BYTES = 536870912; // 512 MiB

    public function __construct(private readonly string $basePath)
    {
    }

    public function latestActive(): ?array
    {
        $stmt = Database::connection()->query(
            'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
             FROM release_installers WHERE active=1
             ORDER BY published_at DESC,id DESC LIMIT 1'
        );
        return $stmt->fetch() ?: null;
    }

    public function listAll(): array
    {
        return Database::connection()->query(
            'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
             FROM release_installers ORDER BY published_at DESC,id DESC'
        )->fetchAll();
    }

    public function findActiveByVersion(string $version): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id,version,major_version,file_path,sha256,file_size,notes,published_at,active
             FROM release_installers WHERE version=? AND active=1 LIMIT 1'
        );
        $stmt->execute([$version]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Installers are manual customer downloads and MUST NOT be returned by
     * /api/v1/releases/latest (which only accepts the official auto-update ZIP).
     */
    public function publishUploaded(array $file, string $version, string $notes = ''): array
    {
        $version = trim($version);
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version)) {
            throw new RuntimeException('Versão inválida. Use o formato 3.6.5.');
        }
        $major = (int) explode('.', $version)[0];
        if ($major < 1) {
            throw new RuntimeException('Versão principal inválida.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $name = (string) ($file['name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Arquivo enviado não é um upload válido.');
        }
        if ($size < 1024 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('O instalador EXE deve ter entre 1 KB e 512 MB.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'exe') {
            throw new RuntimeException('Envie um instalador Windows .exe.');
        }
        self::validateExecutableHeader($tmp);

        $safeVersion = preg_replace('/[^0-9A-Za-z._+-]/', '-', $version) ?: $version;
        $directory = $this->installerDir();
        $fileName = 'CaptionIA-Instalador-' . $safeVersion . '.exe';
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        $staging = $path . '.upload-' . bin2hex(random_bytes(8));

        if (!move_uploaded_file($tmp, $staging)) {
            throw new RuntimeException('Não foi possível armazenar o instalador enviado.');
        }
        $hash = hash_file('sha256', $staging);
        $bytes = filesize($staging);
        if (!is_string($hash) || strlen($hash) !== 64 || $bytes === false) {
            @unlink($staging);
            throw new RuntimeException('Falha ao calcular a integridade do instalador.');
        }
        // Use a version + digest-based name to prevent replacing a file while
        // another user is downloading it. Keep the previously published file
        // until after the database references the new version.
        $fileName = 'CaptionIA-Instalador-' . $safeVersion . '-' . substr($hash, 0, 16) . '.exe';
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (!@rename($staging, $path)) {
            @unlink($staging);
            throw new RuntimeException('Não foi possível publicar o EXE.');
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO release_installers
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
                'storage/installers/' . $fileName,
                $hash,
                (int) $bytes,
                trim($notes) !== '' ? mb_substr(trim($notes), 0, 4000) : null,
            ]);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        return $this->findActiveByVersion($version)
            ?? throw new RuntimeException('EXE publicado, mas não foi possível consultar o registro.');
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = Database::connection()->prepare('UPDATE release_installers SET active=? WHERE id=?');
        $stmt->execute([$active ? 1 : 0, $id]);
        if (!$stmt->rowCount()) {
            $exists = Database::connection()->prepare('SELECT id FROM release_installers WHERE id=?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Instalador não encontrado.');
            }
        }
    }

    public function absolutePath(array $installer): string
    {
        $relative = str_replace('\\', '/', ltrim((string) ($installer['file_path'] ?? ''), '/'));
        if (!str_starts_with($relative, 'storage/installers/')) {
            throw new RuntimeException('Caminho do instalador inválido.');
        }
        $dir = realpath($this->installerDir());
        $file = realpath($this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($dir === false || $file === false || !is_file($file)
            || !str_starts_with($file, rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Instalador não encontrado no armazenamento autorizado.');
        }
        return $file;
    }

    /**
     * Verifica apenas se o upload tem estrutura PE executável. Não autentica o
     * editor e não substitui antivírus, assinatura Authenticode ou SmartScreen.
     */
    public static function validateExecutableHeader(string $path): void
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Não foi possível ler o EXE.');
        }
        try {
            $dos = fread($file, 64);
            if ($dos === false || strlen($dos) !== 64 || substr($dos, 0, 2) !== 'MZ') {
                throw new RuntimeException('O arquivo não possui cabeçalho executável Windows MZ.');
            }
            $offset = unpack('V', substr($dos, 0x3C, 4))[1];
            if ($offset < 64 || $offset > 1048576 || fseek($file, $offset) !== 0 || fread($file, 4) !== "PE\0\0") {
                throw new RuntimeException('O arquivo não possui assinatura PE válida.');
            }
        } finally {
            fclose($file);
        }
    }

    private function installerDir(): string
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installers';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar storage/installers.');
        }
        return $dir;
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'EXE maior que o limite permitido pelo servidor (upload_max_filesize/post_max_size).',
            UPLOAD_ERR_PARTIAL => 'O upload do EXE foi interrompido.',
            UPLOAD_ERR_NO_FILE => 'Selecione um instalador EXE.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário de upload indisponível.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo enviado.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP bloqueou o upload.',
            default => 'Falha no envio do EXE (código ' . $error . ').',
        };
    }
}
