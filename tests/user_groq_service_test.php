<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use CaptionIA\Services\UserGroqService;
$_ENV['APP_KEY']='0123456789abcdef0123456789abcdef';
$key='gsk_'.str_repeat('A',45);
assert(UserGroqService::validFormat($key));
if (!UserGroqService::validFormat($key)) throw new RuntimeException('Formato de chave aceito incorretamente.');
if (UserGroqService::validFormat('gsk_short')) throw new RuntimeException('Chave curta aceita.');
$one=UserGroqService::encrypt($key);
$two=UserGroqService::encrypt($key);
if ($one===$two) throw new RuntimeException('AES-GCM sem IV aleatório.');
if (str_contains($one,$key)) throw new RuntimeException('Chave vaza no ciphertext.');
if (UserGroqService::decrypt($one)!==$key) throw new RuntimeException('AES-GCM não é reversível.');
$raw=base64_decode($one,true);
$raw[strlen($raw)-1]=chr(ord($raw[strlen($raw)-1])^1);
try{UserGroqService::decrypt(base64_encode($raw));throw new RuntimeException('Ciphertext adulterado foi aceito.');}
catch (RuntimeException $e){if (str_contains($e->getMessage(),'aceito')) throw $e;}
$quota=UserGroqService::safeQuota(['x-ratelimit-remaining-tokens'=>'7200','retry-after'=>'32','authorization'=>'Bearer gsk_SENSITIVE']);
if (isset($quota['authorization'])||$quota['retry-after']!=='32') throw new RuntimeException('Quota não sanitizada.');
echo "PASS UserGroqService encryption, tamper detection, quota whitelist, no leakage\n";
