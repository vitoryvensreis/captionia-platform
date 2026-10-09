<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Services/CloudNarrativeService.php';
$service = new \CaptionIA\Services\CloudNarrativeService();
$valid = ['messages'=>[['role'=>'system','content'=>'Selecione falas.'],['role'=>'user','content'=>'S001: abertura; S002: encerramento.']]];
$body=$service->prepare($valid);
if ($body['model'] !== 'openai/gpt-oss-120b') throw new RuntimeException('Modelo incorreto');
if ($body['response_format']['type'] !== 'json_object') throw new RuntimeException('Saída não estruturada');
if (!str_contains($body['messages'][0]['content'],'Não invente falas')) throw new RuntimeException('Sistema confiável ausente');
if ($body['messages'][1]['role'] !== 'user') throw new RuntimeException('Mensagem do usuário teve privilégio elevado');
$bad=[
 ['messages'=>[]],
 ['messages'=>[['role'=>'system','content'=>'Só uma mensagem']]],
 ['messages'=>[['role'=>'assistant','content'=>''],['role'=>'user','content'=>'x']]],
 ['messages'=>[['role'=>'admin','content'=>'a'],['role'=>'user','content'=>'b']]],
 ['messages'=>[['role'=>'system','content'=>str_repeat('a',61000)],['role'=>'user','content'=>'b']]],
];
foreach($bad as $case){$thrown=false;try{$service->prepare($case);}catch(InvalidArgumentException $e){$thrown=true;}if(!$thrown)throw new RuntimeException('Payload inválido aceito');}
echo "PASS cloud narrative: modelo fixo, limite de entrada, privilégio e esquema JSON\n";
