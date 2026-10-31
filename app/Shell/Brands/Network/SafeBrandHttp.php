<?php

namespace App\Shell\Brands\Network;

use App\Shell\Brands\Contracts\DomainDnsResolver;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SafeBrandHttp
{
    public function __construct(private readonly DomainDnsResolver $dns) {}

    public function get(string $url, int $maxBytes = 1048576): Response
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('unsafe_url');
        }
        $host = PublicHost::normalize($parts['host'] ?? '');
        $port = $parts['scheme'] === 'https' ? 443 : 80;
        if (isset($parts['port']) && $parts['port'] !== $port) {
            throw new RuntimeException('unsafe_port');
        }
        $addresses = $this->dns->addresses($host);
        if ($addresses === []) {
            throw new RuntimeException('dns_empty');
        }
        foreach ($addresses as $address) {
            if (! PublicHost::isPublicAddress($address)) {
                throw new RuntimeException('unsafe_address');
            }
        }
        if (! defined('CURLOPT_RESOLVE') || ! function_exists('curl_exec') || ! function_exists('curl_init')) {
            throw new RuntimeException('pinned_transport_unavailable');
        }
        // Pin the checked IP, retain hostname-based TLS verification, disable redirects and proxies.
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        $safeUrl = $parts['scheme'].'://'.$host.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $response = Http::connectTimeout(3)->timeout(8)->setHandler(new CurlHandler)->withHeaders(['User-Agent' => 'Souzpir-Brand-Verification/1.0', 'Accept' => 'text/plain,text/html;q=0.9', 'Accept-Encoding' => 'identity'])
            ->withOptions([
                'allow_redirects' => false, 'stream' => false, 'proxy' => '', 'verify' => true, 'decode_content' => false,
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address]],
                'on_headers' => static function ($response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new RuntimeException('response_too_large');
                    }
                },
                'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                    if ($total > $maxBytes || $downloaded > $maxBytes) {
                        throw new RuntimeException('response_too_large');
                    }
                },
            ])->get($safeUrl);
        if (strlen($response->body()) > $maxBytes) {
            throw new RuntimeException('response_too_large');
        }

        return $response;
    }
}
