<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Licensed referrals; commissions are derived from verified paid orders only.
 *
 * Financial safeguards:
 * - snapshot rate at checkout, reject self-referral
 * - unique commission per order, no double credit on webhook retries
 * - 14-day default holding period, reserved payouts locked in MySQL
 * - manual payouts only: "paid" records a transfer done OUTSIDE the platform
 * - opt-in admin switch OFF by default, no writes to existing payment tables
 */
final class AffiliateService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->get('affiliate_program_enabled', '0') === '1';
    }

    public function config(): array
    {
        $rate = (string) $this->settings->get('affiliate_default_percent', '15');
        return [
            'enabled' => $this->enabled(),
            'rate_percent' => $rate,
            'rate_bps' => self::rateToBps($rate),
            'minimum_cents' => (int) $this->settings->get('affiliate_minimum_withdrawal_cents', '5000'),
            'hold_days' => (int) $this->settings->get('affiliate_hold_days', '14'),
            'auto_enable_buyers' => $this->settings->get('affiliate_auto_enable_buyers', '1') === '1',
        ];
    }

    public static function rateToBps(string $text): int
    {
        $normalized = str_replace(',', '.', trim($text));
        if (!preg_match('/^(?:[0-9]{1,2})(?:\.[0-9]{1,2})?$/D', $normalized)) {
            throw new RuntimeException('Comissão deve ser uma porcentagem entre 1% e 80%.');
        }
        [$major, $minor] = array_pad(explode('.', $normalized), 2, '0');
        $bps = ((int) $major) * 100 + (int) str_pad($minor, 2, '0');
        if ($bps < 100 || $bps > 8000) {
            throw new RuntimeException('Comissão deve ser entre 1% e 80%.');
        }
        return $bps;
    }

    public static function priceCents(string $value): int
    {
        $v = trim($value);
        if (!preg_match('/^\d{1,7}(?:[,.]\d{1,2})?$/D', $v)) {
            throw new RuntimeException('Valor monetário inválido.');
        }
        $v = str_replace(',', '.', $v);
        [$a, $b] = array_pad(explode('.', $v), 2, '0');
        return (int) $a * 100 + (int) str_pad($b, 2, '0');
    }

    public function saveConfig(array $data): void
    {
        $bps = self::rateToBps((string) ($data['percent'] ?? '15'));
        $min = self::priceCents((string) ($data['minimum'] ?? '50'));
        $days = filter_var($data['hold_days'] ?? 14, FILTER_VALIDATE_INT);
        if ($min < 1000 || $min > 10000000) {
            throw new RuntimeException('Saque mínimo deve estar entre R$ 10,00 e R$ 100.000,00.');
        }
        if ($days === false || $days < 0 || $days > 90) {
            throw new RuntimeException('Prazo de retenção deve ser entre 0 e 90 dias.');
        }
        if (!empty($data['enabled'])) {
            self::encryptionKey(); // Payout requests must be usable before enabling.
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->settings->set('affiliate_default_percent', number_format($bps / 100, 2, '.', ''));
            $this->settings->set('affiliate_minimum_withdrawal_cents', (string) $min);
            $this->settings->set('affiliate_hold_days', (string) $days);
            $this->settings->set('affiliate_auto_enable_buyers', empty($data['auto_enable_buyers']) ? '0' : '1');
            $this->settings->set('affiliate_program_enabled', empty($data['enabled']) ? '0' : '1');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function ensureForBuyer(int $userId): ?array
    {
        if (!$this->enabled() || !$this->config()['auto_enable_buyers']) return $this->profile($userId);
        $pdo = Database::connection();
        $exists = $pdo->prepare("SELECT COUNT(*) FROM checkout_orders WHERE user_id=? AND status='paid'");
        $exists->execute([$userId]);
        if ((int)$exists->fetchColumn() < 1) return $this->profile($userId);
        $existing = $this->profile($userId);
        if ($existing) return $existing; // Respect explicit admin deactivation.
        for ($try = 0; $try < 3; $try++) {
            try {
                $pdo->prepare('INSERT IGNORE INTO affiliate_profiles(user_id,code,enabled) VALUES (?,?,1)')
                    ->execute([$userId, strtoupper(bin2hex(random_bytes(8)))]);
                break;
            } catch (\PDOException $e) {
                if ($try === 2) throw $e;
            }
        }
        return $this->profile($userId);
    }

    public function profile(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM affiliate_profiles WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function adminSetEnabled(int $userId, bool $enabled): void
    {
        $pdo = Database::connection();
        $user = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
        $user->execute([$userId]);
        if (!$user->fetchColumn()) throw new RuntimeException('Cliente não encontrado.');
        $existing = $this->profile($userId);
        if ($existing) {
            $pdo->prepare('UPDATE affiliate_profiles SET enabled=? WHERE id=?')->execute([$enabled ? 1 : 0, $existing['id']]);
            return;
        }
        $pdo->prepare('INSERT INTO affiliate_profiles(user_id,code,enabled) VALUES (?,?,?)')
            ->execute([$userId, strtoupper(bin2hex(random_bytes(8))), $enabled ? 1 : 0]);
    }

    public function registerVisit(string $code): void
    {
        if (!$this->enabled() || !preg_match('/^[A-F0-9]{16}$/D', $code)) return;
        $stmt = Database::connection()->prepare(
            'SELECT id,user_id FROM affiliate_profiles WHERE code=? AND enabled=1 LIMIT 1'
        );
        $stmt->execute([$code]);
        $ref = $stmt->fetch();
        if (!$ref) return;
        // 30-day last valid referral; no IP/user-agent or client identity stored.
        setcookie('captionia_ref', $code, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function attributeNewOrder(array $order, int $buyerUserId, ?string $code): void
    {
        if (!$this->enabled() || !preg_match('/^[A-F0-9]{16}$/D', (string) $code)) return;
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT ap.id FROM affiliate_profiles ap
             JOIN checkout_orders co ON co.id=?
             WHERE ap.code=? AND ap.enabled=1 AND ap.user_id<>?
               AND co.user_id=? LIMIT 1'
        );
        $stmt->execute([(int)$order['id'], $code, $buyerUserId, $buyerUserId]);
        $affiliateId = (int) ($stmt->fetchColumn() ?: 0);
        if (!$affiliateId) return;
        $pdo->prepare(
            'INSERT IGNORE INTO affiliate_attributions (order_id,affiliate_id,rate_bps) VALUES (?,?,?)'
        )->execute([(int)$order['id'], $affiliateId, $this->config()['rate_bps']]);
    }

    public static function commissionCents(int $price, int $bps): int
    {
        if ($price <= 0 || $bps < 0 || $bps > 8000) return 0;
        return intdiv($price * $bps + 5000, 10000);
    }

    public function creditPaidOrder(array $order): bool
    {
        if (($order['status'] ?? '') !== 'paid' || (int)($order['license_applied'] ?? 0) !== 1) return false;
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT aa.affiliate_id,aa.rate_bps,co.amount_cents,co.user_id,ap.user_id AS owner_user_id
             FROM affiliate_attributions aa
             JOIN checkout_orders co ON co.id=aa.order_id
             JOIN affiliate_profiles ap ON ap.id=aa.affiliate_id
             WHERE aa.order_id=? AND co.status=\'paid\' AND co.license_applied=1
             LIMIT 1'
        );
        $stmt->execute([(int)$order['id']]);
        $item = $stmt->fetch();
        if (!$item || (int)$item['user_id'] === (int)$item['owner_user_id']) return false;
        $commission = self::commissionCents((int)$item['amount_cents'], (int)$item['rate_bps']);
        if ($commission <= 0) return false;
        $holdDays = max(0, min(90, (int)$this->config()['hold_days']));
        $pdo->prepare(
            'INSERT IGNORE INTO affiliate_commissions
             (order_id,affiliate_id,amount_cents,available_at)
             VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL ' . $holdDays . ' DAY))'
        )->execute([(int)$order['id'], (int)$item['affiliate_id'], $commission]);
        return $pdo->lastInsertId() !== '0';
    }

    public function reconcilePaidOrders(int $max = 100): int
    {
        // Admin-only periodic safety net if webhook credit failed after license activation.
        $max = min(500, max(1, $max));
        $sql = "SELECT co.* FROM checkout_orders co
                JOIN affiliate_attributions a ON a.order_id=co.id
                LEFT JOIN affiliate_commissions c ON c.order_id=co.id
                WHERE co.status='paid' AND co.license_applied=1 AND c.id IS NULL
                ORDER BY co.id DESC LIMIT " . $max;
        $count = 0;
        foreach (Database::connection()->query($sql)->fetchAll() as $row) {
            if ($this->creditPaidOrder($row)) $count++;
        }
        return $count;
    }

    public function balance(int $affiliateId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT
              COALESCE(SUM(CASE WHEN ac.status='earned' THEN ac.amount_cents ELSE 0 END),0) AS total,
              COALESCE(SUM(CASE WHEN ac.status='earned' AND available_at<=NOW() THEN ac.amount_cents ELSE 0 END),0) AS matured
             FROM affiliate_commissions ac
             JOIN checkout_orders co ON co.id=ac.order_id
             WHERE ac.affiliate_id=? AND co.status='paid'"
        );
        $stmt->execute([$affiliateId]);
        $row = $stmt->fetch();
        $payout = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_cents),0) FROM affiliate_withdrawals
             WHERE affiliate_id=? AND status IN ('requested','paid')"
        );
        $payout->execute([$affiliateId]);
        $reserved = (int)$payout->fetchColumn();
        $total = (int)$row['total'];
        $matured = (int)$row['matured'];
        return [
            'deficit_cents' => max(0, $reserved - $matured),
            'earned_cents' => $total,
            'holding_cents' => max(0, $total - $matured),
            'reserved_paid_cents' => $reserved,
            'available_cents' => max(0, $matured - $reserved),
        ];
    }

    public function myDashboard(int $userId): array
    {
        $profile = $this->ensureForBuyer($userId);
        if (!$profile) return ['profile'=>null,'balance'=>null,'withdrawals'=>[],'commissions'=>[]];
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT ac.amount_cents,ac.status,ac.available_at,ac.created_at,co.reference_id
             FROM affiliate_commissions ac JOIN checkout_orders co ON co.id=ac.order_id
             WHERE ac.affiliate_id=? ORDER BY ac.created_at DESC LIMIT 70'
        );
        $stmt->execute([$profile['id']]);
        $commissions = $stmt->fetchAll();
        $stmt = $pdo->prepare(
            'SELECT id,amount_cents,status,requested_at,resolved_at,admin_note
             FROM affiliate_withdrawals WHERE affiliate_id=?
             ORDER BY id DESC LIMIT 50'
        );
        $stmt->execute([$profile['id']]);
        return [
            'profile'=>$profile,
            'balance'=>$this->balance((int)$profile['id']),
            'commissions'=>$commissions,
            'withdrawals'=>$stmt->fetchAll(),
        ];
    }

    public static function encryptionKey(): string
    {
        $key = trim((string)Env::get('AFFILIATE_PAYOUT_KEY', ''));
        if (!preg_match('/^[a-f0-9]{64}$/iD', $key)) {
            throw new RuntimeException('Configure AFFILIATE_PAYOUT_KEY (64 caracteres hexadecimais) no .env antes de habilitar afiliados.');
        }
        return hex2bin($key);
    }

    private static function encryptPix(string $value): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Não foi possível proteger a chave Pix.');
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decryptPix(string $value): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < 29) return 'Indisponível';
        $plain = openssl_decrypt(substr($bytes,28),'aes-256-gcm',self::encryptionKey(),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
        return is_string($plain) ? $plain : 'Indisponível';
    }

    public function requestWithdrawal(int $userId, int $amountCents, string $pixKey): void
    {
        if (!$this->enabled()) throw new RuntimeException('Programa de afiliados indisponível.');
        $config=$this->config();
        $pixKey=trim($pixKey);
        if (mb_strlen($pixKey)<5 || mb_strlen($pixKey)>140 || preg_match('/[\r\n\x00-\x1F]/', $pixKey)) {
            throw new RuntimeException('Informe uma chave Pix válida.');
        }
        if ($amountCents < $config['minimum_cents']) throw new RuntimeException('O valor está abaixo do saque mínimo.');
        $encrypted=self::encryptPix($pixKey);
        $pdo=Database::connection();
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare('SELECT * FROM affiliate_profiles WHERE user_id=? FOR UPDATE');
            $stmt->execute([$userId]);$profile=$stmt->fetch();
            if (!$profile || !(int)$profile['enabled']) throw new RuntimeException('Seu acesso ao programa de afiliados está desativado.');
            $available=$this->balance((int)$profile['id'])['available_cents'];
            if ($amountCents>$available) throw new RuntimeException('Saldo liberado insuficiente para este saque.');
            $pdo->prepare('INSERT INTO affiliate_withdrawals (affiliate_id,amount_cents,pix_key_ciphertext,status) VALUES (?,?,?,\'requested\')')
                ->execute([(int)$profile['id'],$amountCents,$encrypted]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function decideWithdrawal(int $withdrawalId, int $adminId, string $action, string $note): void
    {
        if (!in_array($action,['paid','rejected'],true))throw new RuntimeException('Ação inválida.');
        $note=mb_substr(trim($note),0,500);
        if ($action==='rejected' && $note==='')throw new RuntimeException('Informe o motivo da recusa.');
        $pdo=Database::connection();$pdo->beginTransaction();
        try{
            // Lock profile first, same order as requestWithdrawal.
            $find=$pdo->prepare('SELECT affiliate_id FROM affiliate_withdrawals WHERE id=?');
            $find->execute([$withdrawalId]);$affId=(int)$find->fetchColumn();
            if(!$affId)throw new RuntimeException('Saque não encontrado.');
            $stmt=$pdo->prepare('SELECT id FROM affiliate_profiles WHERE id=? FOR UPDATE');
            $stmt->execute([$affId]);$stmt->fetchColumn();
            $stmt=$pdo->prepare('SELECT status FROM affiliate_withdrawals WHERE id=? AND affiliate_id=? FOR UPDATE');
            $stmt->execute([$withdrawalId,$affId]);
            if($stmt->fetchColumn()!=='requested')throw new RuntimeException('Este saque já foi processado.');
            $pdo->prepare('UPDATE affiliate_withdrawals SET status=?,resolved_by=?,admin_note=?,resolved_at=NOW() WHERE id=? AND status=\'requested\'')
                ->execute([$action,$adminId,$note ?: null,$withdrawalId]);
            $pdo->prepare('INSERT INTO audit_logs (user_id,event_type,metadata_json) VALUES (?,?,?)')
                ->execute([$adminId,'affiliate.withdrawal_'.$action,json_encode(['withdrawal_id'=>$withdrawalId],JSON_THROW_ON_ERROR)]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function adminDashboard(): array
    {
        $pdo=Database::connection();
        $users=$pdo->query(
            'SELECT u.id,u.name,u.email,ap.id AS affiliate_id,ap.code,ap.enabled,
             (SELECT COUNT(*) FROM checkout_orders o WHERE o.user_id=u.id AND o.status=\'paid\') AS paid_orders
             FROM users u LEFT JOIN affiliate_profiles ap ON ap.user_id=u.id
             ORDER BY u.created_at DESC LIMIT 150'
        )->fetchAll();
        $payouts=$pdo->query(
            'SELECT aw.*,u.name,u.email FROM affiliate_withdrawals aw
             JOIN affiliate_profiles ap ON ap.id=aw.affiliate_id JOIN users u ON u.id=ap.user_id
             ORDER BY (aw.status=\'requested\') DESC,aw.id DESC LIMIT 100'
        )->fetchAll();
        foreach($payouts as &$p){$p['pix_key']=self::decryptPix((string)$p['pix_key_ciphertext']);unset($p['pix_key_ciphertext']);}
        unset($p);
        $revenue=$pdo->query(
            "SELECT COUNT(*) AS total_orders,COALESCE(SUM(amount_cents),0) AS earned_cents,
               COALESCE(SUM(CASE WHEN available_at<=NOW() THEN amount_cents ELSE 0 END),0) AS matured_cents
             FROM affiliate_commissions WHERE status='earned'"
        )->fetch();
        return ['config'=>$this->config(),'users'=>$users,'withdrawals'=>$payouts,'summary'=>$revenue];
    }
}
