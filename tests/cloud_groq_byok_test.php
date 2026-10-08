<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CaptionIA\Services\CloudTranscriptionService;

$assert=static function(bool $ok,string $what):void {
    if(!$ok)throw new RuntimeException('Cloud Groq BYOK: '.$what);
};
foreach(['gsk_'.str_repeat('a',28),'gsk_'.str_repeat('a',100)] as $value){
    $assert(CloudTranscriptionService::validGroqKey($value),'valid personal key');
}
foreach(['','gsk_short','sk_'.str_repeat('a',30),'gsk_hello\r\nInjected',
         'gsk_'.str_repeat('a',201),'gsk_'.str_repeat(' ',30)] as $value){
    $assert(!CloudTranscriptionService::validGroqKey($value),'reject unsafe API key');
}

$app=file_get_contents(dirname(__DIR__).'/app/Core/App.php');
$assert(is_string($app),'app source');
$assert(!str_contains($app,'Limite diário de transcrições atingido.'),'daily quota disabled');
$assert(!str_contains($app,'Consentimento para enviar áudio é obrigatório.'),'checkbox gate removed');
$assert(str_contains($app,"'cloud_transcription_personal_key' => true"),'BYOK available');
$assert(str_contains($app,"'personal_groq_key'"),'transient key input wired');
$assert(str_contains($app,"'transcribe' ? 2"),'per-minute protection preserved');

echo "Cloud BYOK validation PASS: no daily quota, no checkbox, valid personal key, 2/min retained\n";
