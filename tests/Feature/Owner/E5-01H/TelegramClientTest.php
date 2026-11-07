<?php

namespace Tests\Feature\Owner\E501H;

use App\Seller\Channels\Telegram\HttpTelegramClient;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class TelegramClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('http', new Factory);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_fake_http_verifies_bot_and_rejects_invalid_responses(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => ['is_bot' => true, 'first_name' => 'Бот', 'username' => 'sample_bot']], 200)
            ->push(['ok' => false], 401)
            ->push(['ok' => true, 'result' => ['is_bot' => false]], 200)]);
        $client = app(HttpTelegramClient::class);
        self::assertSame(['name' => 'Бот', 'username' => 'sample_bot'], $client->bot('123:abc'));
        self::assertNull($client->bot('123:abc'));
        self::assertNull($client->bot('123:abc'));
        self::assertNull($client->bot('invalid'));
        Http::assertSentCount(3);
    }

    public function test_live_telegram_is_off_by_default(): void
    {
        self::assertFalse((require __DIR__.'/../../../../config/features/E5-01H.php')['defaults']['live_enabled']);
    }
}
