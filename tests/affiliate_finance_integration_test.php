<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CaptionIA\Core\Database;
use CaptionIA\Core\Env;
use CaptionIA\Services\AffiliateService;
use CaptionIA\Services\FinanceService;
use CaptionIA\Services\SettingsService;

Env::load(dirname(__DIR__));
$pdo=Database::connection();
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Finance regression: '.$message);
};
$unique=bin2hex(random_bytes(5));
$mail1='affiliate-'.$unique.'@example.test';
$mail2='buyer-'.$unique.'@example.test';
$insert=$pdo->prepare(
    "INSERT INTO users (name,email,cpf,phone,password_hash,role,status) VALUES (?,?,?,'0000000000','test-hash','customer','active')"
);
$insert->execute(['Affiliate Tester',$mail1,'00000000000']);
$affiliateUser=(int)$pdo->lastInsertId();
$insert->execute(['Buyer Tester',$mail2,'11111111111']);
$buyer=(int)$pdo->lastInsertId();
$planId=(int)$pdo->query("SELECT id FROM plans WHERE code='monthly' LIMIT 1")->fetchColumn();
$assert($planId>0,'plan seed');
$settings=new SettingsService();
$affiliate=new AffiliateService($settings);
$settings->set('affiliate_default_percent','15.00');
$settings->set('affiliate_hold_days','0');
$settings->set('affiliate_minimum_withdrawal_cents','1000');
$settings->set('affiliate_program_enabled','1');
$settings->set('affiliate_auto_enable_buyers','1');

$createOrder=static function(int $userId,string $status='paid',int $amount=10000)use($pdo,$planId):array{
    $ref='TEST-'.strtoupper(bin2hex(random_bytes(8)));
    $s=$pdo->prepare(
        "INSERT INTO checkout_orders
         (reference_id,user_id,plan_id,status,license_applied,amount_cents,provider_mode,expires_at,paid_at)
         VALUES (?,?,?, ?,1,?,'bacen_static_pix',DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())"
    );
    $s->execute([$ref,$userId,$planId,$status,$amount]);
    return ['id'=>(int)$pdo->lastInsertId(),'status'=>$status,'license_applied'=>1];
};
$createOrder($affiliateUser);
$profile=$affiliate->ensureForBuyer($affiliateUser);
$assert(is_array($profile),'auto enrollment');
$assert(preg_match('/^[A-F0-9]{16}$/',$profile['code'])===1,'random referral code');
$checkout=$createOrder($buyer,'paid',10000);
$affiliate->attributeNewOrder($checkout,$buyer,$profile['code']);
$affRow=$pdo->prepare('SELECT rate_bps FROM affiliate_attributions WHERE order_id=?');
$affRow->execute([$checkout['id']]);
$assert((int)$affRow->fetchColumn()===1500,'rate snapshot');
$assert($affiliate->creditPaidOrder($checkout),'first commission created');
$affiliate->creditPaidOrder($checkout);
$balance=$affiliate->balance((int)$profile['id']);
$assert($balance['earned_cents']===1500,'credited 15%, never duplicated');
$assert($balance['available_cents']===1500,'zero-day hold');
$pdo->prepare("UPDATE checkout_orders SET status='declined' WHERE id=?")->execute([$checkout['id']]);
$assert($affiliate->balance((int)$profile['id'])['available_cents']===0,'reversed order excluded from withdrawable balance');
$pdo->prepare("UPDATE checkout_orders SET status='paid' WHERE id=?")->execute([$checkout['id']]);
$assert($affiliate->balance((int)$profile['id'])['available_cents']===1500,'valid restored payment reinstates balance');

$another=$createOrder($buyer,'waiting');
$affiliate->attributeNewOrder($another,$buyer,$profile['code']);
$assert(!$affiliate->creditPaidOrder($another),'never credit unpaid');
$self=$createOrder($affiliateUser);
$affiliate->attributeNewOrder($self,$affiliateUser,$profile['code']);
$find=$pdo->prepare('SELECT COUNT(*) FROM affiliate_attributions WHERE order_id=?');
$find->execute([$self['id']]);
$assert((int)$find->fetchColumn()===0,'block self referral');

$affiliate->requestWithdrawal($affiliateUser,1000,'test-pix-key@example.com');
$balance=$affiliate->balance((int)$profile['id']);
$assert($balance['available_cents']===500,'requested amount reserved');
try{$affiliate->requestWithdrawal($affiliateUser,1000,'test-pix-key@example.com');throw new RuntimeException('double spend allowed');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'insuficiente'),'repeated withdrawal blocked');}
$withdrawalId=(int)$pdo->query('SELECT MAX(id) FROM affiliate_withdrawals')->fetchColumn();
$w=$pdo->query('SELECT pix_key_ciphertext FROM affiliate_withdrawals WHERE id='.$withdrawalId)->fetchColumn();
$assert(!str_contains((string)$w,'test-pix-key@example.com'),'PIX encrypted at rest');
$assert(AffiliateService::decryptPix((string)$w)==='test-pix-key@example.com','decrypt as admin');
$affiliate->decideWithdrawal($withdrawalId,$affiliateUser,'rejected','Dados de teste');
$assert($affiliate->balance((int)$profile['id'])['available_cents']===1500,'rejected payout restores available');
$affiliate->requestWithdrawal($affiliateUser,1000,'test-pix-key@example.com');
$second=(int)$pdo->query('SELECT MAX(id) FROM affiliate_withdrawals')->fetchColumn();
$affiliate->decideWithdrawal($second,$affiliateUser,'paid','Transferido (teste)');
$assert($affiliate->balance((int)$profile['id'])['available_cents']===500,'paid payout deducted permanently');
try{$affiliate->decideWithdrawal($second,$affiliateUser,'paid','Again');throw new RuntimeException('repeat payout allowed');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'processado'),'double completion blocked');}

$analytics=new FinanceService($settings);
$visit=bin2hex(random_bytes(16));
try{$analytics->track(['visit_id'=>$visit,'event'=>'page_view','page'=>'home'],null);throw new RuntimeException('privacy violation');}
catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'consentimento'),'consent required');}
$analytics->track(['visit_id'=>$visit,'event'=>'page_view','page'=>'home','consent'=>true],null);
$analytics->track(['visit_id'=>$visit,'event'=>'scroll_50','page'=>'home','consent'=>true],null);
$analytics->track(['visit_id'=>$visit,'event'=>'plan_click','page'=>'home','consent'=>true],null);
$summary=$analytics->summary(30);
$assert(($summary['events']['scroll_50']['count']??0)>=1,'scroll depth metrics');
$assert(($summary['orders']['orders']??0)>=3,'orders financial reporting');
$assert(AffiliateService::commissionCents(10000,1500)===1500,'integer cents rate');
$assert(AffiliateService::priceCents('50,75')===5075,'BRL parser');
$assert(AffiliateService::rateToBps('12,5')===1250,'BPS parser');

echo "Financial integration PASS: referral, paid-only commission, idempotency, encryption, payout reservation/rejection and consensual funnel\n";
