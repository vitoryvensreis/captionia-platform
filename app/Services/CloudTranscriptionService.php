<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use CaptionIA\Core\Env;
use InvalidArgumentException;
use RuntimeException;

/**
 * Private Groq proxy. API key never leaves the server; uploaded audio is kept
 * only in PHP's temporary upload file for the duration of this request.
 */
final class CloudTranscriptionService
{
    private const MAX_BYTES = 24000000;

    public function __construct(private readonly string $userKey = '') {}

    public function transcribe(array $file, string $language): array
    {
        $key = trim($this->userKey);
        if (!str_starts_with($key, 'gsk_') || strlen($key) < 25) {
            throw new RuntimeException('Configure sua chave Groq em Minha conta no site Caption IA.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('cURL indisponível.');
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Arquivo de áudio não recebido.');
        }
        $path = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $original = basename(str_replace('\\','/',(string)($file['name'] ?? 'audio.wav')));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = [
            'wav'=>'audio/wav','mp3'=>'audio/mpeg','m4a'=>'audio/mp4',
            'mp4'=>'video/mp4','ogg'=>'audio/ogg','flac'=>'audio/flac',
            'webm'=>'audio/webm'
        ];
        if ($size < 100 || $size > self::MAX_BYTES || !$path || !is_uploaded_file($path) || !isset($mime[$ext])) {
            throw new InvalidArgumentException('Formato ou tamanho inválido. Envie áudio de até 24 MB.');
        }
        if ($language !== '' && !preg_match('/^[a-z]{2}$/D', $language)) {
            throw new InvalidArgumentException('Idioma deve ser código ISO de duas letras.');
        }
        $name = 'captionia-audio.' . $ext;
        $post = [
            'file' => new \CURLFile($path,$mime[$ext],$name),
            'model' => 'whisper-large-v3',
            'response_format' => 'verbose_json',
            'timestamp_granularities[]' => 'word',
            'temperature' => '0'
        ];
        if ($language !== '') $post['language'] = $language;
        $curl = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        if ($curl === false) throw new RuntimeException('Falha de inicialização da transcrição.');
        $body = '';
        $rateHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rateHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $rateHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key,'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        try {
            $ok = curl_exec($curl);
            $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        if ($ok === false || $code < 200 || $code >= 300) {
            // Never send upstream error bodies or credentials back to plugin clients.
            if ($code === 429) throw new GroqGatewayException('Limite de tokens Groq atingido. Aguarde e tente novamente.', 429, min(180, max(1, (int)($rateHeaders['retry-after'] ?? 30))));
            if ($code === 401 || $code === 403) throw new GroqGatewayException('Sua chave Groq foi revogada ou não tem acesso. Atualize-a na plataforma.', 403);
            throw new GroqGatewayException('Serviço de transcrição temporariamente indisponível.', 503);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('Resposta de transcrição inválida.');
        $words = [];
        foreach (($decoded['words'] ?? []) as $word) {
            if (!is_array($word)) continue;
            $text = trim((string)($word['word'] ?? $word['text'] ?? ''));
            $start = (float)($word['start'] ?? -1);
            $end = (float)($word['end'] ?? -1);
            if ($text === '' || !is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start) continue;
            $words[] = ['word'=>$text,'start'=>$start,'end'=>$end];
            if (count($words) > 4000) throw new RuntimeException('Transcrição longa demais para uma única etapa.');
        }
        if (!$words) throw new RuntimeException('O provedor não retornou palavras temporizadas.');
        return [
            'words'=>$words,
            'text'=>mb_substr((string)($decoded['text'] ?? ''),0,12000),
            'language'=>(string)($decoded['language'] ?? $language),
            'provider'=>'groq',
            'model'=>'whisper-large-v3',
            'rate_limit'=>UserGroqService::safeQuota($rateHeaders)
        ];
    }
}
