<?php

namespace App\Shell\Brands\Network;

use InvalidArgumentException;

final class PublicHost
{
    public static function normalize(string $host): string
    {
        $ascii = idn_to_ascii(rtrim(strtolower(trim($host)), '.'), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($ascii) || strlen($ascii) > 253 || ! str_contains($ascii, '.') ||
            ! filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || filter_var($ascii, FILTER_VALIDATE_IP) ||
            preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|example|onion)$/i', $ascii) ||
            ! preg_match('/\.(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/i', $ascii)) {
            throw new InvalidArgumentException('Укажите публичное доменное имя без протокола, порта и пути.');
        }

        return $ascii;
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $blocked = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '3fff::/20', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        $bytes = inet_pton($ip);
        // The verifier only supports global-unicast IPv6 in this range; fail closed elsewhere.
        if (strlen($bytes) === 16 && (ord($bytes[0]) & 0xE0) !== 0x20) {
            return false;
        }
        foreach ($blocked as $range) {
            [$network, $bits] = explode('/', $range);
            $networkBytes = inet_pton($network);
            if (strlen($bytes) !== strlen($networkBytes)) {
                continue;
            }
            $whole = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($bytes, 0, $whole) === substr($networkBytes, 0, $whole) &&
                ($rest === 0 || ((ord($bytes[$whole]) ^ ord($networkBytes[$whole])) & (255 << (8 - $rest))) === 0)) {
                return false;
            }
        }

        return true;
    }
}
