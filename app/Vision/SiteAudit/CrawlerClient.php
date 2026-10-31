<?php

namespace App\Vision\SiteAudit;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** One bounded HTTP hop. Redirect and robots policy belongs to the caller. */
class CrawlerClient
{
    public const MAX_BYTES = 600000;

    public function __construct(private PublicUrlPolicy $policy) {}

    public function fetch(string $url, float $timeoutSeconds = 48): array
    {
        if (! config('features.E8-01B.defaults.fetch_enabled', false)) {
            throw new RuntimeException('Загрузка сайтов не включена в этом окружении.');
        }
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Безопасный обход требует cURL с привязкой DNS.');
        }
        $target = $this->policy->target($url);
        $timeoutSeconds = max(0.1, min(48, $timeoutSeconds));
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
        $response = Http::withHeaders(['User-Agent' => 'eyon-audit', 'Accept-Encoding' => 'identity'])
            ->withoutRedirecting()->connectTimeout(min(3, $timeoutSeconds))->timeout($timeoutSeconds)
            ->withOptions([
                'proxy' => '', 'protocols' => ['http', 'https'], 'verify' => true, 'cookies' => false, 'decode_content' => false,
                'curl' => [
                    CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$address],
                    CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                ],
                'on_headers' => static function (ResponseInterface $response): void {
                    if ((float) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new RuntimeException('Страница превышает допустимый размер.');
                    }
                },
                'progress' => static function ($total, $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new RuntimeException('Страница превышает допустимый размер.');
                    }
                },
            ])->get($target['url']);
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES || (float) $response->header('Content-Length') > self::MAX_BYTES) {
            throw new RuntimeException('Страница превышает допустимый размер.');
        }
        if (! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
            throw new RuntimeException('Сжатый ответ не поддерживается безопасным обходом.');
        }
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($body !== '' && ! in_array($contentType, ['text/html', 'application/xhtml+xml', 'text/plain', 'text/xml', 'application/xml'], true)) {
            throw new RuntimeException('Тип ответа не поддерживается безопасным обходом.');
        }
        $charset = 'UTF-8';
        foreach (array_slice(explode(';', $response->header('Content-Type')), 1) as $parameter) {
            if (preg_match('/^\s*charset\s*=\s*(.*?)\s*$/i', $parameter, $match)) {
                $charset = trim($match[1], " \t\n\r\0\x0B\"'");
            }
        }
        try {
            if (! mb_check_encoding($body, $charset)) {
                throw new RuntimeException('Страница содержит некорректный текст.');
            }
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (\ValueError) {
            throw new RuntimeException('Кодировка ответа не поддерживается безопасным обходом.');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('Текст страницы превышает допустимый размер.');
        }

        return ['url' => $target['url'], 'body' => $body, 'status' => $response->status(),
            'content_type' => $contentType, 'location' => $response->header('Location'),
            'x_robots_tag' => $response->header('X-Robots-Tag'), 'fetched_at' => now()->toIso8601String()];
    }
}
