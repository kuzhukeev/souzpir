<?php

namespace Tests\Feature\Shell\Support;

use App\Shell\Support\Contracts\ProviderCooldowns;
use App\Shell\Support\StatusQuery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;

final class SupportReadModelTest extends SupportTestCase
{
    public function test_help_and_status_do_not_invent_materials_provider_health_or_incident_history(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $this->get('/help')->assertInertia(fn (Assert $page) => $page->component('Shell/Support/Help')
            ->where('help.configured', false)->has('help.sections', 6)->has('help.articles', 0)->where('help.sections.0.url', null));
        $this->get('/help/status')->assertInertia(fn (Assert $page) => $page->component('Shell/Support/Status')
            ->where('status.health_url', '/up')->where('status.components.0.status', 'operational')->where('status.components.1.status', 'operational')
            ->where('status.components.2.status', 'unknown')->where('status.providers.available', false)
            ->where('status.providers.items.0.status', 'unknown')->where('status.incidents.available', false)->has('status.incidents.data', 0));
        config(['features.E1-12.defaults.knowledge_base_url' => 'javascript:alert(1)', 'features.E1-12.defaults.articles' => [
            ['id' => 'bad', 'title' => 'Untrusted', 'section_id' => 'start', 'url' => 'data:text/html,no'],
            ['id' => 'good', 'title' => 'Настроенная статья', 'section_id' => 'start', 'url' => 'https://docs.example.test/start'],
        ]]);
        $this->get('/help')->assertInertia(fn (Assert $page) => $page->where('help.configured', true)->where('help.knowledge_base_url', null)
            ->has('help.articles', 1)->where('help.articles.0.id', 'good'));
    }

    public function test_future_cooldown_read_boundary_expires_without_claiming_provider_is_healthy(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $until = now()->addMinutes(10)->startOfSecond();
        $source = new class($until->toIso8601String()) implements ProviderCooldowns
        {
            public function __construct(private string $until) {}

            public function snapshot(TenantContext $context): array
            {
                return ['available' => true, 'checked_at' => now()->toIso8601String(), 'cooldowns' => ['gemini' => $this->until, 'not-a-configured-provider' => $this->until]];
            }
        };
        app()->instance(ProviderCooldowns::class, $source);
        $this->get('/help/status')->assertInertia(fn (Assert $page) => $page->where('status.providers.available', true)
            ->where('status.providers.items.0.status', 'cooldown')->where('status.providers.items.0.cooldown_until', $until->toIso8601String())->has('status.providers.items', 8));
        $this->travelTo($until);
        $this->get('/help/status')->assertInertia(fn (Assert $page) => $page->where('status.providers.items.0.status', 'unknown')->where('status.providers.items.0.cooldown_until', null));
    }

    public function test_database_and_provider_diagnostics_fail_soft_without_raw_error_text(): void
    {
        $manager = DB::getFacadeRoot();
        $failed = Mockery::mock(get_class($manager));
        $failed->shouldReceive('select')->with('select 1')->once()->andThrow(new RuntimeException('DB-private-connection-details'));
        $failed->shouldReceive('table')->with('ai_providers')->once()->andThrow(new RuntimeException('DB-private-connection-details'));
        DB::swap($failed);
        try {
            $result = app(StatusQuery::class)->read();
            self::assertSame('degraded', $result['components'][1]['status']);
            self::assertFalse($result['providers']['available']);
            self::assertSame([], $result['providers']['items']);
            self::assertStringNotContainsString('DB-private-connection-details', json_encode($result));
        } finally {
            DB::swap($manager);
        }
    }
}
