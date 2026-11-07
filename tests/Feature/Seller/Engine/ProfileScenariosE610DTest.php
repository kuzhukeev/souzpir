<?php

namespace Tests\Feature\Seller\Engine;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileScenariosE610DTest extends TenancyTestCase
{
    public function test_two_profile_schemas_publish_separately_and_sandbox_runs_fake_without_external_calls(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake', 'features.E3-06D.defaults.runtime_enabled' => true,
            'features.E0-04.defaults.external_delivery' => false, 'features.E5-01H.defaults.live_enabled' => false,
            'features.E1-01.defaults.verification_enabled' => false, 'features.E1-10.defaults.webhook_delivery' => false,
            'features.E0-05.defaults.fixture_directory' => storage_path('framework/testing/empty-e610d')]);
        $profiles = json_decode(file_get_contents(base_path('tests/fixtures/E6-10D/profiles.json')), true, flags: JSON_THROW_ON_ERROR);
        [$owner, $org, $service, $school, $membership] = $this->cabinet();
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $published = [];
        foreach (['service' => $service, 'school' => $school] as $key => $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
            $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $invalid = $profiles[$key]['fields'];
            $invalid[0]['id'] = 'email';
            $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => $invalid])->assertSessionHasErrors('fields.0.id');
            $this->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => $profiles[$key]['fields']])->assertRedirect()->assertSessionHasNoErrors();
            $published[$key] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draft, $profiles, $key): string {
                $repo = app(KnowledgeRepository::class);
                $snapshot = $repo->publish($tenant, $draft['id'], 2);
                self::assertEquals($profiles[$key]['fields'], $snapshot->policy()['client_fields']);

                return $snapshot->releaseId();
            });
            $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->where('publishedClientFields.0.name', $profiles[$key]['fields'][0]['name']));
            foreach ($profiles[$key]['messages'] as $message) {
                $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published[$key], 'text' => $message])->assertOk()->assertJsonPath('status', 'completed');
            }
        }
        $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published['service'], 'text' => 'Проверка'])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$service->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $service->id]);
        $this->post('/seller/scenario/fields/draft', ['version' => 2, 'fields' => $profiles['service']['fields']])->assertForbidden();
        self::assertDatabaseCount('conversations', 0);
        self::assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
