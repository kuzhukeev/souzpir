<?php

namespace Tests\Feature\Launch\L1_03;

use App\Ai\Gateway;
use App\Contracts\Ai\AiContext;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BrandAiPolicyTest extends TenancyTestCase
{
    public function test_owner_sees_deny_default_and_can_allow_a_class(): void
    {
        [$owner, $organization, $selected, $target] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])
            ->get($this->url($target))->assertInertia(fn (Assert $page) => $page
            ->where('aiDataPolicy.conversation_content', false)
            ->where('aiDataPolicy.knowledge_public', false)
            ->where('permissions.manageAiPolicy', true));

        $this->patch($this->url($target).'/ai-policy', $this->allow(['conversation_content' => true]))
            ->assertRedirect($this->url($target))->assertSessionHasNoErrors();

        $settings = $this->settings($target);
        self::assertSame(['any'], $settings['ai_data_policy']['conversation_content']);
        self::assertSame([], $settings['ai_data_policy']['knowledge_public']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'brand_id' => $target->id, 'action' => 'brand.ai_policy.updated']);
    }

    public function test_admin_and_member_cannot_change_ai_policy(): void
    {
        [, $organization, $selected, $target] = $this->cabinet();
        [$admin] = $this->member($organization, 'admin', [$target->id]);
        [$member] = $this->member($organization, 'member', [$target->id]);

        $this->actingAs($admin)->withSession(['brand_id' => $target->id])
            ->patch($this->url($target).'/ai-policy', $this->allow(['conversation_content' => true]))->assertForbidden();
        $this->actingAs($member)->withSession(['brand_id' => $target->id])
            ->patch($this->url($target).'/ai-policy', $this->allow(['conversation_content' => true]))->assertForbidden();

        self::assertSame([], $this->settings($target)['ai_data_policy'] ?? []);
    }

    public function test_update_rejects_missing_or_non_boolean_fields_without_mutation(): void
    {
        [$owner, , $selected, $target] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])
            ->patch($this->url($target).'/ai-policy', ['conversation_content' => true])
            ->assertSessionHasErrors(['knowledge_internal', 'vision_question', 'knowledge_public']);
        $this->patch($this->url($target).'/ai-policy', $this->allow(['conversation_content' => 'yes']))
            ->assertSessionHasErrors('conversation_content');
        self::assertSame([], $this->settings($target)['ai_data_policy'] ?? []);
    }

    public function test_brand_of_another_organization_is_not_reachable(): void
    {
        [$owner] = $this->cabinet();
        $otherOwner = $this->user();
        $otherOrganization = Organization::create(['name' => 'Other org', 'owner_user_id' => $otherOwner->id]);
        $otherBrand = $this->within($otherOrganization, null, fn () => Brand::create(['name' => 'Other brand', 'theme' => 'neutral']));

        $this->actingAs($owner)->get($this->url($otherBrand))->assertNotFound();
        $this->patch($this->url($otherBrand).'/ai-policy', $this->allow(['conversation_content' => true]))->assertNotFound();
    }

    public function test_gateway_rejects_live_call_without_policy_and_allows_it_once_granted(): void
    {
        [$owner, $organization, $selected, $target] = $this->cabinet();
        config(['features.E0-05.defaults.live_enabled' => true]);
        $context = new AiContext($organization->id, $target->id, 'test', 'seller', 'conversation', (string) Str::ulid(), 'idem-key', ['conversation_content']);
        $method = new \ReflectionMethod(Gateway::class, 'assertLiveAllowed');
        $gateway = app(Gateway::class);

        try {
            $method->invoke($gateway, $context);
            self::fail('Live call must be rejected without brand consent.');
        } catch (LogicException $error) {
            self::assertSame('Brand data policy does not authorize this external AI transfer.', $error->getMessage());
        }

        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])
            ->patch($this->url($target).'/ai-policy', $this->allow(['conversation_content' => true]))
            ->assertSessionHasNoErrors();

        $method->invoke($gateway, $context);
        self::assertTrue(true);
    }

    public function test_changing_knowledge_public_while_a_vision_grant_is_active_is_blocked_with_a_clear_message(): void
    {
        [$owner, $organization, $selected, $target] = $this->cabinet();
        DB::table('brands')->where('id', $target->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
        $this->activeVisionGrant($organization, $target, $owner);

        $this->actingAs($owner)->withSession(['brand_id' => $selected->id])
            ->patch($this->url($target).'/ai-policy', $this->allow(['knowledge_public' => false]))
            ->assertSessionHasErrors('knowledge_public')->assertStatus(302);
        self::assertStringContainsString('Видения', session('errors')->first('knowledge_public'));
        self::assertSame(['any'], $this->settings($target)['ai_data_policy']['knowledge_public']);

        $this->artisan('brand:ai-policy', ['brand' => $target->id, '--deny' => 'knowledge_public'])
            ->assertExitCode(ConsoleCommand::FAILURE);
        self::assertSame(['any'], $this->settings($target)['ai_data_policy']['knowledge_public']);
    }

    public function test_console_shows_current_policy_and_applies_allow_and_deny(): void
    {
        [, , , $target] = $this->cabinet();

        $this->artisan('brand:ai-policy', ['brand' => $target->id])
            ->expectsOutputToContain('conversation_content: deny')
            ->assertExitCode(ConsoleCommand::SUCCESS);

        $this->artisan('brand:ai-policy', ['brand' => $target->id, '--allow' => 'conversation_content,knowledge_public'])
            ->expectsOutputToContain('conversation_content: allow')
            ->expectsOutputToContain('knowledge_internal: deny')
            ->assertExitCode(ConsoleCommand::SUCCESS);
        self::assertSame(['any'], $this->settings($target)['ai_data_policy']['conversation_content']);
        self::assertSame(['any'], $this->settings($target)['ai_data_policy']['knowledge_public']);

        $this->artisan('brand:ai-policy', ['brand' => $target->id, '--deny' => 'conversation_content'])
            ->assertExitCode(ConsoleCommand::SUCCESS);
        self::assertSame([], $this->settings($target)['ai_data_policy']['conversation_content']);
        self::assertSame(['any'], $this->settings($target)['ai_data_policy']['knowledge_public']);

        $this->artisan('brand:ai-policy', ['brand' => $target->id, '--allow' => 'not_a_class'])
            ->assertExitCode(ConsoleCommand::FAILURE);
        $this->artisan('brand:ai-policy', ['brand' => $target->id, '--allow' => 'conversation_content', '--deny' => 'conversation_content'])
            ->assertExitCode(ConsoleCommand::FAILURE);
    }

    private function activeVisionGrant(Organization $organization, Brand $brand, User $owner): void
    {
        $releaseId = (string) Str::ulid();
        DB::table('knowledge_releases')->insert(['id' => $releaseId, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => 1, 'status' => 'published', 'checksum' => str_repeat('a', 64), 'published_at' => now(),
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_knowledge_grants')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'release_id' => $releaseId, 'purpose' => 'vision', 'data_class' => 'knowledge_public',
            'policy_checksum' => str_repeat('b', 64), 'issued_by' => $owner->id, 'created_at' => now()]);
    }

    /** @param array<string,bool> $overrides */
    private function allow(array $overrides): array
    {
        return array_replace(['conversation_content' => false, 'knowledge_internal' => false, 'vision_question' => false, 'knowledge_public' => false], $overrides);
    }

    private function url(Brand $brand): string
    {
        return '/brands/'.$brand->id.'/settings';
    }

    private function settings(Brand $brand): array
    {
        return json_decode(DB::table('brands')->where('id', $brand->id)->value('settings'), true, flags: JSON_THROW_ON_ERROR) ?? [];
    }
}
