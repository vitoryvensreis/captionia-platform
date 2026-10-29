<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use RuntimeException;

final class SettingsService
{
    private array $cache = [];

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $stmt = Database::connection()->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1'
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            $value = $default;
        }

        return $this->cache[$key] = $value !== null ? (string) $value : null;
    }

    public function set(string $key, string $value): void
    {
        if (!preg_match('/^[a-z0-9_.-]{2,80}$/', $key)) {
            throw new RuntimeException('Chave de configuração inválida.');
        }

        Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key,setting_value,updated_at)
             VALUES (?,?,NOW())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()'
        )->execute([$key, $value]);

        $this->cache[$key] = $value;
    }

    public function paymentGateway(): string
    {
        $gateway = strtolower((string) $this->get('payment_gateway', Env::get('PAYMENT_GATEWAY', 'bacen_pix')));
        if ($gateway === 'asaas' && !AsaasService::paymentsEnabled()) {
            // Keep the previous live Pix flow until Asaas has been homologated.
            return 'bacen_pix';
        }
        return in_array($gateway, ['asaas', 'pagbank', 'bacen_pix'], true) ? $gateway : 'bacen_pix';
    }

    public function pixKey(): string
    {
        return trim((string) $this->get('bacen_pix_key', Env::get('BACEN_PIX_KEY', '')));
    }

    public function pixName(): string
    {
        return trim((string) $this->get('bacen_pix_name', Env::get('BACEN_PIX_NAME', 'CAPTION IA')));
    }

    public function pixCity(): string
    {
        return trim((string) $this->get('bacen_pix_city', Env::get('BACEN_PIX_CITY', 'FORTALEZA')));
    }

    public function paymentSettings(): array
    {
        return [
            'gateway' => $this->paymentGateway(),
            'pix_key' => $this->pixKey(),
            'pix_name' => $this->pixName(),
            'pix_city' => $this->pixCity(),
        ];
    }

    public function savePaymentSettings(string $gateway, string $pixKey, string $pixName, string $pixCity): void
    {
        $gateway = strtolower(trim($gateway));
        if (!in_array($gateway, ['asaas', 'pagbank', 'bacen_pix'], true)) {
            throw new RuntimeException('Forma de pagamento inválida.');
        }

        $pixKey = trim($pixKey);
        $pixName = trim($pixName);
        $pixCity = trim($pixCity);

        if ($gateway === 'bacen_pix' && $pixKey === '') {
            throw new RuntimeException('Informe a chave Pix para usar o Pix direto.');
        }
        if ($pixKey !== '' && mb_strlen($pixKey) > 77) {
            throw new RuntimeException('A chave Pix excede o limite do BR Code.');
        }
        if ($pixName === '' || mb_strlen($pixName) > 25) {
            throw new RuntimeException('O nome do recebedor deve ter entre 1 e 25 caracteres.');
        }
        if ($pixCity === '' || mb_strlen($pixCity) > 15) {
            throw new RuntimeException('A cidade deve ter entre 1 e 15 caracteres.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->set('payment_gateway', $gateway);
            $this->set('bacen_pix_key', $pixKey);
            $this->set('bacen_pix_name', $pixName);
            $this->set('bacen_pix_city', $pixCity);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
