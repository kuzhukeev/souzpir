<?php

namespace Tests\Feature\Seller\Sandbox;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SandboxTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_check_draft_guard_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['guard_rules'] = ['forbidden_promises' => [['id' => 'r', 'description' => 'Отсрочка', 'patterns' => ['отсрочка'], 'severity' => 'block']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/sandbox')->assertOk()->assertInertia(fn ($page) => $page->has('releases', 1));
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => $draft['id'], 'text' => 'Предоставим отсрочка'])->assertOk()->assertJsonCount(1, 'issues');
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => $draft['id'], 'text' => 'Здравствуйте'])->assertOk()->assertJsonCount(0, 'issues');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/sandbox')->assertOk()->assertInertia(fn ($page) => $page->has('releases', 0));
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => $draft['id'], 'text' => 'Текст'])->assertNotFound();
    }

    public function test_validation_and_viewer_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => 'bad', 'text' => 'Текст'])->assertUnprocessable()->assertJsonValidationErrors('release');
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => (string) Str::ulid(), 'text' => ''])->assertUnprocessable()->assertJsonValidationErrors('text');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/sandbox')->assertForbidden();
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => (string) Str::ulid(), 'text' => 'Текст'])->assertForbidden();
    }
}
