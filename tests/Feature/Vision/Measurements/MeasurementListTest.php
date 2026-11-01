<?php

namespace Tests\Feature\Vision\Measurements;

use App\Models\Brand;
use App\Models\Organization;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Vision\Measurements\MeasurementsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementListTest extends TenancyTestCase
{
    private function enable(Organization $organization, Brand $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stored(Organization $organization, Brand $brand, string $status): string
    {
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => DB::table('question_sets')->where('brand_id', $brand->id)->count() + 1,
            'questions' => json_encode(['Тестовый вопрос']), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('measurements')->insert(['id' => $id, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => $status, 'configuration' => json_encode(['private' => 'never disclose']), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function headers(string $brand, string $revision): array
    {
        return ['Accept' => 'application/json', 'X-Brand-Id' => $brand, 'X-Brand-Revision' => $revision];
    }

    public function test_empty_brand_and_revoked_module_do_not_return_data(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = $this->headers($brand->id, $revision);
        $this->withHeaders($headers)->getJson('/vision/runs/data')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'vision')->update(['enabled' => false]);
        $this->withHeaders($headers)->getJson('/vision/runs/data')->assertNotFound();
    }

    public function test_real_stored_rows_are_scoped_paginated_and_do_not_disclose_private_payloads(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org, $brand);
        $this->enable($org, $other);
        $ids = [];
        foreach (['pending', 'running', 'completed', 'partial', 'failed'] as $status) {
            $ids[] = $this->stored($org, $brand, $status);
        }
        for ($i = 0; $i < 22; $i++) {
            $this->stored($org, $brand, 'pending');
        }
        $otherId = $this->stored($org, $other, 'completed');
        [$foreign, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $foreignId = $this->stored($foreignOrg, $foreignBrand, 'completed');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/vision/runs')->assertOk();
        $revision = $page->inertiaProps('measurementBrandRevision');
        $this->assertStringNotContainsString($ids[0], $page->getContent());
        $this->getJson('/vision/runs/data')->assertStatus(409)->assertDontSee($ids[0]);
        $headers = $this->headers($brand->id, $revision);
        $this->withHeaders($this->headers($brand->id, str_repeat('0', 64)))->getJson('/vision/runs/revision')->assertStatus(409);
        $this->withHeaders($headers)->getJson('/vision/runs/revision')->assertOk()->assertJsonPath('revision', $revision);
        $first = $this->withHeaders($headers)->getJson('/vision/runs/data')->assertOk()->assertJsonPath('total', 27)->assertJsonCount(25, 'data')->assertJsonPath('last_page', 2);
        $second = $this->withHeaders($headers)->getJson('/vision/runs/data?page=2')->assertOk()->assertJsonCount(2, 'data');
        $all = collect([...$first->json('data'), ...$second->json('data')]);
        $this->assertEqualsCanonicalizing(['pending', 'running', 'completed', 'partial', 'failed'], $all->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $all[0]['size']);
        $payload = $first->getContent().$second->getContent();
        foreach ([$otherId, $foreignId, 'never disclose', 'configuration', 'profile_snapshot', $other->id, $foreign->email] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->withHeaders($headers)->getJson('/vision/runs/data?page=0')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_stale_revision_brand_switch_revocation_and_permissions_fail_closed(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        $this->enable($org, $first);
        $this->enable($org, $second);
        $firstId = $this->stored($org, $first, 'pending');
        $secondId = $this->stored($org, $second, 'running');
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$first->id]);
        [$manager] = $this->member($org, 'member', [$first->id], ['sales_manager' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $revA = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders($this->headers($first->id, $revA))->getJson('/vision/runs/data')->assertOk()->assertSee($firstId)->assertDontSee($secondId);
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->withHeaders($this->headers($first->id, $revA))->getJson('/vision/runs/data')->assertStatus(404)->assertDontSee($firstId);
        $this->flushHeaders();
        $revB = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders($this->headers($second->id, $revB))->getJson('/vision/runs/data')->assertOk()->assertSee($secondId)->assertDontSee($firstId);
        $this->post('/brand/switch', ['brand_id' => $first->id])->assertRedirect();
        $this->withHeaders($this->headers($first->id, $revA))->getJson('/vision/runs/data')->assertStatus(409)->assertDontSee($firstId);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $revViewer = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders($this->headers($first->id, $revViewer))->getJson('/vision/runs/data')->assertOk()->assertSee($firstId);
        DB::table('memberships')->where('id', $viewerMembership->id)->update(['status' => 'disabled']);
        $this->withHeaders($this->headers($first->id, $revViewer))->getJson('/vision/runs/data')->assertForbidden()->assertDontSee($firstId);
        DB::table('memberships')->where('id', $viewerMembership->id)->update(['status' => 'active']);
        $this->actingAs($manager)->withSession(['brand_id' => $first->id])->get('/vision/runs')->assertForbidden();
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $revViewer = $this->get('/vision/runs')->inertiaProps('measurementBrandRevision');
        DB::table('auth_session_registry')->where('user_id', $viewer->id)->update(['revoked_at' => now()]);
        $this->withHeaders($this->headers($first->id, $revViewer))->getJson('/vision/runs/data')->assertUnauthorized()->assertDontSee($firstId);
    }

    public function test_switch_between_tenant_resolution_and_data_read_rejects_the_stale_request(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        $this->enable($org, $first);
        $this->enable($org, $second);
        $secret = $this->stored($org, $first, 'running');
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $revision = $this->get('/vision/runs')->inertiaProps('measurementBrandRevision');
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'auth.version'])->get('/vision/test-delayed-list', function (Request $request) use ($org, $second) {
            app(AuthSessionRegistry::class)->withinActive($request, function () use ($request, $org, $second): void {
                app(SessionBrandSelection::class)->select($request, $org->id, $second->id, $request->attributes->get('tenant.brand_revision'));
            });

            return app(MeasurementsController::class)->data($request);
        });
        $this->withHeaders($this->headers($first->id, $revision))->getJson('/vision/test-delayed-list')->assertStatus(409)->assertDontSee($secret);
    }
}
