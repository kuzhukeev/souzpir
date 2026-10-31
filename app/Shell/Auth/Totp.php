<?php

namespace App\Shell\Auth;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    public function matchingCounter(string $secret, string $code, int $timestamp, ?int $lastCounter): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }
        $current = intdiv($timestamp, 30);
        foreach ([$current + 1, $current, $current - 1] as $counter) {
            if ($counter < 0 || ($lastCounter !== null && $counter <= $lastCounter)) {
                continue;
            }
            if (hash_equals($this->at($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    public function at(string $secret, int $counter): string
    {
        if ($counter < 0 || ! preg_match('/^[A-Z2-7]{16,128}$/D', $secret)) {
            throw new InvalidArgumentException('Invalid TOTP secret or counter.');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $bytes, true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
