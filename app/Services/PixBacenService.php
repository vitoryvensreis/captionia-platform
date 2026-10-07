<?php
declare(strict_types=1);

namespace CaptionIA\Services;

use RuntimeException;

final class PixBacenService
{
    public function payload(
        string $pixKey,
        int $amountCents,
        string $merchantName,
        string $merchantCity,
        string $txid,
        string $description = ''
    ): string {
        $pixKey = trim($pixKey);
        if ($pixKey === '' || mb_strlen($pixKey) > 77) {
            throw new RuntimeException('Chave Pix inválida.');
        }
        if ($amountCents <= 0) {
            throw new RuntimeException('Valor do Pix inválido.');
        }

        $merchantName = $this->emvText($merchantName, 25);
        $merchantCity = $this->emvText($merchantCity, 15);
        $description = $this->emvText($description, 50);
        $txid = strtoupper(preg_replace('/[^A-Z0-9]/', '', $this->ascii($txid)) ?? '');
        $txid = substr($txid !== '' ? $txid : '***', 0, 25);

        $mai = $this->field('00', 'br.gov.bcb.pix')
            . $this->field('01', $pixKey);

        if ($description !== '') {
            $maxDescription = max(0, 99 - strlen($mai) - 4);
            if ($maxDescription > 0) {
                $description = substr($description, 0, min(72, $maxDescription));
                if ($description !== '') {
                    $mai .= $this->field('02', $description);
                }
            }
        }

        $amount = number_format($amountCents / 100, 2, '.', '');

        $payload = $this->field('00', '01')
            . $this->field('26', $mai)
            . $this->field('52', '0000')
            . $this->field('53', '986')
            . $this->field('54', $amount)
            . $this->field('58', 'BR')
            . $this->field('59', $merchantName)
            . $this->field('60', $merchantCity)
            . $this->field('62', $this->field('05', $txid))
            . '6304';

        return $payload . strtoupper(str_pad(dechex($this->crc16($payload)), 4, '0', STR_PAD_LEFT));
    }

    public function validatePayload(string $payload): bool
    {
        if (strlen($payload) < 20 || !str_ends_with(substr($payload, 0, -4), '6304')) {
            return false;
        }

        $body = substr($payload, 0, -4);
        $crc = strtoupper(substr($payload, -4));
        $expected = strtoupper(str_pad(dechex($this->crc16($body)), 4, '0', STR_PAD_LEFT));
        return hash_equals($expected, $crc);
    }

    private function field(string $id, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            throw new RuntimeException('Campo BR Code excede 99 caracteres.');
        }

        return $id . str_pad((string) $length, 2, '0', STR_PAD_LEFT) . $value;
    }

    private function emvText(string $value, int $max): string
    {
        $value = strtoupper(trim($this->ascii($value)));
        $value = preg_replace('/[^A-Z0-9 $%*+\-.\/]/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return substr(trim($value), 0, $max);
    }

    private function ascii(string $value): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted !== false ? $converted : $value;
    }

    private function crc16(string $payload): int
    {
        $result = 0xFFFF;

        for ($offset = 0, $length = strlen($payload); $offset < $length; $offset++) {
            $result ^= ord($payload[$offset]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                if (($result & 0x8000) !== 0) {
                    $result = (($result << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $result = ($result << 1) & 0xFFFF;
                }
            }
        }

        return $result & 0xFFFF;
    }
}
