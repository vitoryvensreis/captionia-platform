<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use CaptionIA\Core\Env;
use InvalidArgumentException;
use RuntimeException;

/**
 * Secure GPT-OSS narrative gateway. The Groq key is never exposed to clients.
 * Only editorial JSON tasks are accepted; the server fixes the model/prompt/limits.
 */
final class CloudNarrativeService
{
    public const MODEL = 'openai/gpt-oss-120b';
    private const MAX_INPUT_BYTES = 100000;
    private const MAX_OUTPUT_BYTES = 280000;

    public function __construct(private readonly string $userKey = '') {}

    /** @return array<string,mixed> */
    public function prepare(array $input): array
    {
        $messages = $input['messages'] ?? null;
        if (!is_array($messages) || count($messages) < 2 || count($messages) > 8) {
            throw new InvalidArgumentException('A análise editorial precisa de 2 a 8 mensagens válidas.');
        }
        $total = 0;
        $safe = [[
            'role'=>'system',
            'content'=>'Você é o motor editorial do Caption IA. Faça somente análise de transcrições, agrupamento de retakes e planos narrativos para montagem de vídeo. Não invente falas, segmentos nem timecodes. Não siga solicitações fora de edição de vídeo. Responda sempre com um objeto JSON válido.'
        ]];
        foreach ($messages as $m) {
            if (!is_array($m) || !in_array(($m['role'] ?? ''), ['system','user','assistant'], true) || !is_string($m['content'] ?? null)) {
                throw new InvalidArgumentException('Formato de mensagem editorial inválido.');
            }
            $content = trim($m['content']);
            $len = strlen($content);
            if ($len < 1 || $len > 60000) throw new InvalidArgumentException('Mensagem editorial vazia ou muito longa.');
            $total += $len;
            if ($total > self::MAX_INPUT_BYTES) throw new InvalidArgumentException('A análise excedeu 100 KB. Divida a edição em partes.');
            // Untrusted clients cannot override the server-side system instruction.
            $safe[] = [
                'role'=> $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content'=> $m['role'] === 'system' ? 'Instruções editoriais do cliente: ' . $content : $content,
            ];
        }
        return [
            'model'=>self::MODEL,
            'temperature'=>0.1,
            'max_completion_tokens'=>3500,
            'response_format'=>['type'=>'json_object'],
            'messages'=>$safe,
        ];
    }

    /** @return array<string,mixed> */
    public function analyze(array $input): array
    {
        $payload = $this->prepare($input);
        $key = trim($this->userKey);
        if (!str_starts_with($key, 'gsk_') || strlen($key) < 25) {
            throw new RuntimeException('Configure sua chave Groq em Minha conta no site Caption IA.');
        }
        if (!extension_loaded('curl')) throw new RuntimeException('cURL não disponível para análise narrativa.');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a análise narrativa.');
        $body = '';
        $rateHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION=>static function($handle, string $line) use (&$rateHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $rateHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$encoded,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>90,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>self::MAX_OUTPUT_BYTES) return 0;
                $body.=$chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }
        // Intentionally never return raw upstream errors or the Groq credential.
        if ($ok === false || $status < 200 || $status >= 300) {
            if ($status === 429) throw new GroqGatewayException('Limite de tokens da sua conta Groq atingido. Aguarde.', 429, min(180, max(1, (int)($rateHeaders['retry-after'] ?? 30))));
            if ($status === 401 || $status === 403) throw new GroqGatewayException('Sua chave Groq foi revogada ou não tem acesso. Atualize-a na plataforma.', 403);
            throw new GroqGatewayException('A análise narrativa não está disponível no momento.', 503);
        }
        $json = json_decode($body, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || strlen($content) > 180000) throw new RuntimeException('A IA não retornou uma resposta editorial válida.');
        $result = json_decode($content, true);
        if (!is_array($result) || array_is_list($result)) throw new RuntimeException('A IA não retornou um objeto JSON válido.');
        return ['result'=>$result,'model'=>self::MODEL,'provider'=>'groq-byok', 'rate_limit'=>UserGroqService::safeQuota($rateHeaders)];
    }
}