<?php

namespace App\Seller\Channels\Widget;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

/** Активная проверка установки виджета: GET на сайт клиента с защитой от SSRF. Решение владельца 07.11.2026 (Q1). */
final class WidgetInstallationChecker
{
    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 5;

    private const MAX_BYTES = 1_048_576;

    private const USER_AGENT = 'SouzpirWidgetCheck/1.0';

    private const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8', '::1/128', 'fc00::/7', 'fe80::/10'];

    /** @return array{status:'detected'|'not_detected'|'unreachable'|'failed', message: string} */
    public function check(string $origin, string $publicKey): array
    {
        $url = $origin;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $this->assertPublic($url);
            } catch (\Throwable $error) {
                return ['status' => 'failed', 'message' => $error->getMessage()];
            }

            try {
                $response = Http::withUserAgent(self::USER_AGENT)
                    ->withHeaders(['Accept' => 'text/html'])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (ConnectionException) {
                return ['status' => 'unreachable', 'message' => 'Сайт недоступен: таймаут'];
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! $location || $hop === self::MAX_REDIRECTS) {
                    return ['status' => 'failed', 'message' => 'Проверка не удалась: слишком много переходов'];
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                return ['status' => 'unreachable', 'message' => 'Сайт недоступен: HTTP '.$response->status()];
            }

            $found = str_contains(substr($response->body(), 0, self::MAX_BYTES), $publicKey);

            return $found
                ? ['status' => 'detected', 'message' => 'Код установлен']
                : ['status' => 'not_detected', 'message' => 'Код не найден на странице'];
        }

        return ['status' => 'failed', 'message' => 'Проверка не удалась: слишком много переходов'];
    }

    private function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new \RuntimeException('Проверка не удалась: недопустимый адрес.');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new \RuntimeException('Проверка не удалась: разрешены только порты 80 и 443.');
        }
        $addresses = $this->resolve(strtolower($parts['host']));
        if ($addresses === []) {
            throw new \RuntimeException('Проверка не удалась: не удалось определить адрес сайта.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP) || IpUtils::checkIp($address, self::PRIVATE_RANGES)) {
                throw new \RuntimeException('Сайт разрешается в запрещённый сетевой адрес.');
            }
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
