<?php

namespace App\Shell\Integrations\Webhooks;

use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Shell\Brands\Network\PublicHost;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class WebhookDelivery
{
    public function __construct(private readonly DomainDnsResolver $dns) {}

    /** Delivery requires a verified subscription snapshot and a manual-test receipt. */
    public static function available(): bool
    {
        return false;
    }

    public function send(string $id): void
    {
        // Neither the legacy automatic rows nor manual test rows have the proof
        // needed to distinguish their authorization. A config flag must not
        // enable network delivery until that contract is implemented.
        if (! self::available()) {
            return;
        }
        $claimed = DB::table('webhook_deliveries')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $row = DB::table('webhook_deliveries')->where('id', $id)->first();
        $hook = DB::table('webhooks')->where('id', $row->webhook_id)->where('organization_id', $row->organization_id)->where('brand_id', $row->brand_id)->first();
        if (! $hook) {
            DB::table('webhook_deliveries')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);

            return;
        }
        $body = json_encode(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $statusCode = null;
            try {
                $parts = parse_url($hook->url);
                if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                    throw new RuntimeException('unsafe_url');
                }
                $host = PublicHost::normalize($parts['host'] ?? '');
                $addresses = $this->dns->addresses($host);
                if ($addresses === []) {
                    throw new RuntimeException('dns_empty');
                }
                foreach ($addresses as $address) {
                    if (! PublicHost::isPublicAddress($address)) {
                        throw new RuntimeException('unsafe_address');
                    }
                }
                if (! defined('CURLOPT_RESOLVE') || ! function_exists('curl_exec')) {
                    throw new RuntimeException('pinned_transport_unavailable');
                }
                $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
                $safeUrl = 'https://'.$host.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
                $secret = Crypt::decryptString($hook->secret);
                $signature = hash_hmac('sha256', $body, $secret);
                $response = Http::connectTimeout(3)->timeout(8)->setHandler(new CurlHandler)->withHeaders([
                    'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                    'X-Souzpir-Signature' => 'sha256='.$signature, 'X-Souzpir-Delivery' => $id,
                ])->withOptions([
                    'allow_redirects' => false, 'stream' => false, 'proxy' => '', 'verify' => true, 'decode_content' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]],
                    'on_headers' => static function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 65536) {
                            throw new RuntimeException('response_too_large');
                        }
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > 65536 || $downloaded > 65536) {
                            throw new RuntimeException('response_too_large');
                        }
                    },
                ])->withBody($body, 'application/json')->post($safeUrl);
                $statusCode = $response->status();
                if ($response->successful() && strlen($response->body()) <= 65536) {
                    DB::table('webhook_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'attempts' => $attempt, 'status_code' => $statusCode, 'updated_at' => now()]);

                    return;
                }
            } catch (Throwable) {
                // Never store exception text: URLs and remote errors can contain credentials.
            }
            DB::table('webhook_deliveries')->where('id', $id)->where('status', 'sending')->update(['attempts' => $attempt, 'status_code' => $statusCode, 'updated_at' => now()]);
        }
        DB::table('webhook_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
