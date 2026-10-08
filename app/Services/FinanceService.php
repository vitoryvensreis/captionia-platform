<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use CaptionIA\Core\Database;
use RuntimeException;

/**
 * LGPD-conscious, opt-in funnel analytics. Never stores IP address, URL,
 * form text, CPF, payment tokens or any fingerprint.
 */
final class FinanceService
{
    public const EVENT_TYPES=[
        'page_view','pricing_view','plan_click','signup_view','checkout_view',
        'checkout_submit','payment_view','payment_confirmed',
        'scroll_25','scroll_50','scroll_75','scroll_100',
    ];
    public const PAGE_KEYS=['home','signup','checkout','payment','account'];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function analyticsEnabled(): bool
    {
        return $this->settings->get('analytics_enabled','1')==='1';
    }

    public function track(array $data, ?int $userId): void
    {
        if (!$this->analyticsEnabled()) return;
        if (($data['consent']??false)!==true)throw new RuntimeException('Métricas requerem consentimento.');
        $visit=(string)($data['visit_id']??'');
        $type=(string)($data['event']??'');
        $page=(string)($data['page']??'');
        if (!preg_match('/^[a-f0-9]{32}$/D',$visit)
            || !in_array($type,self::EVENT_TYPES,true)
            || !in_array($page,self::PAGE_KEYS,true)) {
            throw new RuntimeException('Evento de métricas inválido.');
        }
        // Only accepted navigation funnels; never arbitrary URLs/field values.
        if (str_starts_with($type,'scroll_') && $page!=='home')return;
        if ($type==='plan_click' && $page!=='home')return;
        if ($type==='payment_confirmed')return; // server-owned paid order is source of truth
        $pdo=Database::connection();
        $limit=$pdo->prepare(
            'SELECT COUNT(*) FROM site_funnel_events WHERE visit_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)'
        );
        $limit->execute([$visit]);
        if((int)$limit->fetchColumn()>=65)return;
        $pdo->prepare('INSERT INTO site_funnel_events(visit_id,user_id,event_type,page_key) VALUES (?,?,?,?)')
            ->execute([$visit,$userId,$type,$page]);
    }

    public function summary(int $days=30): array
    {
        $days=max(1,min(365,$days));
        $pdo=Database::connection();
        $where='created_at>=DATE_SUB(NOW(),INTERVAL '.$days.' DAY)';
        $sales=$pdo->query(
            "SELECT COUNT(*) AS orders,
             SUM(status='paid') AS paid_orders,
             COALESCE(SUM(CASE WHEN status='paid' THEN amount_cents ELSE 0 END),0) AS paid_revenue_cents,
             COALESCE(SUM(CASE WHEN status IN ('waiting','creating') THEN amount_cents ELSE 0 END),0) AS pending_cents,
             SUM(status IN ('failed','declined','expired','cancelled')) AS lost_orders
             FROM checkout_orders WHERE $where"
        )->fetch();
        $events=$pdo->query(
            "SELECT event_type,COUNT(*) AS count,COUNT(DISTINCT visit_id) AS visitors
             FROM site_funnel_events WHERE $where GROUP BY event_type"
        )->fetchAll();
        $byEvent=[];
        foreach($events as $event)$byEvent[$event['event_type']]=[
            'count'=>(int)$event['count'],'visitors'=>(int)$event['visitors']
        ];
        $unique=(int)$pdo->query("SELECT COUNT(DISTINCT visit_id) FROM site_funnel_events WHERE $where")->fetchColumn();
        $daily=$pdo->query(
            "SELECT DATE(created_at) AS day,COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status='paid' THEN amount_cents ELSE 0 END),0) AS revenue
            FROM checkout_orders WHERE $where GROUP BY DATE(created_at) ORDER BY day DESC"
        )->fetchAll();
        $plans=$pdo->query(
            "SELECT p.name AS plan_name,
                COUNT(*) AS orders,
                SUM(co.status='paid') AS paid_orders,
                COALESCE(SUM(CASE WHEN co.status='paid' THEN co.amount_cents ELSE 0 END),0) AS revenue
             FROM checkout_orders co JOIN plans p ON p.id=co.plan_id
             WHERE co.$where GROUP BY p.id,p.name ORDER BY revenue DESC"
        )->fetchAll();
        $latest=$pdo->query(
            'SELECT co.reference_id,co.status,co.amount_cents,co.created_at,co.paid_at,
                p.name AS plan_name,u.name AS buyer_name,u.email AS buyer_email
             FROM checkout_orders co JOIN users u ON u.id=co.user_id
             JOIN plans p ON p.id=co.plan_id
             ORDER BY co.id DESC LIMIT 100'
        )->fetchAll();
        $commissions=$pdo->query(
            "SELECT
              COALESCE(SUM(CASE WHEN status='earned' THEN amount_cents ELSE 0 END),0) AS commissions_cents
             FROM affiliate_commissions WHERE $where"
        )->fetch();
        $withdrawals=$pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN status='requested' THEN amount_cents ELSE 0 END),0) AS requested_cents,
                COALESCE(SUM(CASE WHEN status='paid' THEN amount_cents ELSE 0 END),0) AS paid_cents
             FROM affiliate_withdrawals WHERE requested_at>=DATE_SUB(NOW(),INTERVAL $days DAY)"
        )->fetch();
        return [
            'days'=>$days,'orders'=>$sales,'visitors'=>$unique,'events'=>$byEvent,
            'daily'=>$daily,'plans'=>$plans,'latest'=>$latest,
            'commissions'=>$commissions,'withdrawals'=>$withdrawals,
        ];
    }
}
