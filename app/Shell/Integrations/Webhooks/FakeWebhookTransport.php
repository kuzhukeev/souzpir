<?php

namespace App\Shell\Integrations\Webhooks;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

/** In-memory transport: it cannot open a network connection. */
class FakeWebhookTransport
{
    public function send(string $url, string $body, array $headers): int
    {
        $client = new Client(['handler' => new MockHandler([new Response(200)]), 'http_errors' => false]);

        return $client->post($url, ['body' => $body, 'headers' => $headers])->getStatusCode();
    }
}
