<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Sale management: corrections affect dashboards, never payment provider data.
 *
 * Both adjustments and reversible archival are audited atomically; they never
 * edit checkout_orders, payment_transactions, license or affiliate ledgers.
 */
final class SaleManagementService
{
    public function details(string $reference): array
    {
        $stmt=Database::connection()->prepare(
            'SELECT co.id,co.reference_id,co.amount_cents,co.status,
              o.display_amount_cents,o.archived_at,o.archived_reason,
              o.updated_at AS override_updated_at,
              u.name AS buyer_name,u.email AS buyer_email
             FROM checkout_orders co
             LEFT JOIN order_admin_overrides o ON o.order_id=co.id
             JOIN users u ON u.id=co.user_id
             WHERE co.reference_id=? LIMIT 1'
        );
        $stmt->execute([$reference]);
        $row=$stmt->fetch();
        if (!$row)throw new RuntimeException('Venda não encontrada.');
        $history=Database::connection()->prepare(
            'SELECT l.action,l.before_amount_cents,l.after_amount_cents,l.reason,l.created_at,u.name AS admin_name
             FROM order_admin_change_log l JOIN users u ON u.id=l.admin_user_id
             WHERE l.order_id=? ORDER BY l.id DESC LIMIT 60'
        );
        $history->execute([(int)$row['id']]);
        return ['sale'=>$row,'history'=>$history->fetchAll()];
    }

    public function updateValue(string $reference,string $newValue,string $reason,int $adminUserId): void
    {
        $value=AffiliateService::priceCents($newValue);
        if($value<=0 || $value>100000000)throw new RuntimeException('Informe valor entre R$ 0,01 e R$ 1.000.000,00.');
        $this->mutate($reference,$adminUserId,'adjust_value',$reason,$value);
    }

    public function resetValue(string $reference,string $reason,int $adminUserId): void
    {
        $this->mutate($reference,$adminUserId,'reset_value',$reason);
    }

    public function archive(string $reference,string $reason,int $adminUserId): void
    {
        $this->mutate($reference,$adminUserId,'archive',$reason);
    }

    public function restore(string $reference,string $reason,int $adminUserId): void
    {
        $this->mutate($reference,$adminUserId,'restore',$reason);
    }

    private function mutate(string $reference,int $adminUserId,string $action,string $reason,?int $value=null): void
    {
        $reason=trim($reason);
        if(mb_strlen($reason)<10 || mb_strlen($reason)>500)throw new RuntimeException('Informe um motivo de 10 a 500 caracteres.');
        if($adminUserId<1)throw new RuntimeException('Administrador inválido.');

        $pdo=Database::connection();
        $pdo->beginTransaction();
        try {
            // Serialise all changes to a sale on its existing payment row.
            $stmt=$pdo->prepare('SELECT id,amount_cents,status FROM checkout_orders WHERE reference_id=? FOR UPDATE');
            $stmt->execute([$reference]);$order=$stmt->fetch();
            if(!$order)throw new RuntimeException('Venda não encontrada.');

            $stmt=$pdo->prepare('SELECT * FROM order_admin_overrides WHERE order_id=? FOR UPDATE');
            $stmt->execute([(int)$order['id']]);$current=$stmt->fetch() ?: null;
            $effectiveBefore=$current['display_amount_cents']??$order['amount_cents'];
            $beforeOverride=$current['display_amount_cents']??null;
            $afterOverride=$beforeOverride;
            $archived=$current && $current['archived_at']!==null;

            if($action==='adjust_value'){
                if($archived)throw new RuntimeException('Restaure a venda antes de ajustar seu valor.');
                if($value===(int)$effectiveBefore)throw new RuntimeException('O novo valor é igual ao atual.');
                $afterOverride=$value;
            }elseif($action==='reset_value'){
                if($archived)throw new RuntimeException('Restaure a venda antes de redefinir seu valor.');
                if($beforeOverride===null)throw new RuntimeException('O valor já é o original.');
                $afterOverride=null;
            }elseif($action==='archive'){
                if($archived)throw new RuntimeException('A venda já está arquivada.');
            }elseif($action==='restore'){
                if(!$archived)throw new RuntimeException('A venda já está visível.');
            }else throw new RuntimeException('Operação inválida.');

            $isArchive=$action==='archive';
            $isRestore=$action==='restore';
            $archiveValue=$isArchive?date('Y-m-d H:i:s'):($isRestore?null:($current['archived_at']??null));
            $archiveReason=$isArchive?$reason:($isRestore?null:($current['archived_reason']??null));

            $pdo->prepare(
                'INSERT INTO order_admin_overrides
                  (order_id,display_amount_cents,archived_at,archived_reason,updated_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   display_amount_cents=VALUES(display_amount_cents),
                   archived_at=VALUES(archived_at),
                   archived_reason=VALUES(archived_reason),
                   updated_by=VALUES(updated_by)'
            )->execute([(int)$order['id'],$afterOverride,$archiveValue,$archiveReason,$adminUserId]);

            $pdo->prepare(
                'INSERT INTO order_admin_change_log
                 (order_id,admin_user_id,action,before_amount_cents,after_amount_cents,reason)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                (int)$order['id'],$adminUserId,$action,(int)$effectiveBefore,
                $afterOverride===null?(int)$order['amount_cents']:$afterOverride,$reason
            ]);
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id,event_type,metadata_json,created_at)
                 VALUES (?,\'sales.admin_updated\',?,NOW())'
            )->execute([$adminUserId,json_encode([
                'order_id'=>(int)$order['id'],'action'=>$action,
                'before_amount_cents'=>(int)$effectiveBefore,
                'after_amount_cents'=>$afterOverride===null?(int)$order['amount_cents']:$afterOverride,
                'reason'=>$reason
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);

            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
}
