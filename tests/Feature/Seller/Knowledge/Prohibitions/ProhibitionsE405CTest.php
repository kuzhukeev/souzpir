<?php

namespace Tests\Feature\Seller\Knowledge\Prohibitions;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProhibitionsE405CTest extends TenancyTestCase
{
    public function test_enabled_rules_and_highlight_use_saved_brand_settings(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['guard_rules'] = ['style' => [['id' => 'style', 'check' => 'persona_stop_phrases']], 'forbidden_promises' => [['id' => 'one', 'description' => 'Отсрочка', 'severity' => 'block', 'patterns' => ['отсрочка']]]];
            app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['version' => 1, 'topics' => [], 'rules' => [['id' => 'one', 'severity' => 'block', 'enabled' => true, 'patterns' => ['кредит']]], 'maxWords' => 80, 'maxQuestions' => 2, 'stopPhrases' => ['канцелярит']];
        $this->post('/seller/knowledge/prohibitions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/prohibitions')->assertOk()->assertInertia(fn ($page) => $page->where('rules.0.patterns.0', 'кредит')->where('rules.0.enabled', true)->where('maxQuestions', 2)->where('stopPhrases.0', 'канцелярит'));
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'Мы точно дадим кредит'])->assertOk()->assertJsonFragment(['запрещённое обещание: Отсрочка'])->assertJsonPath('matches.0.text', 'кредит');
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'Это канцелярит'])->assertOk()->assertJsonFragment(['стоп-фраза «канцелярит» — скажи проще, по-человечески']);
        $disabled = [...$input, 'version' => 2, 'stopPhrasesEnabled' => false, 'rules' => [['id' => 'one', 'severity' => 'block', 'enabled' => false, 'patterns' => ['кредит']]]];
        $this->post('/seller/knowledge/prohibitions', $disabled)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'Это кредит и канцелярит'])->assertOk()->assertJsonPath('issues', [])->assertJsonPath('matches', []);
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => ''])->assertUnprocessable();
        $this->post('/seller/knowledge/prohibitions', [...$input, 'version' => 3, 'rules' => [['id' => 'one', 'severity' => 'block', 'patterns' => ['(']]]])->assertSessionHasErrors('rules');
        $draftId = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $this->post('/seller/knowledge/'.$draftId.'/publish', ['version' => 3])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $snapshot = app(KnowledgeRepository::class)->published($tenant);
            self::assertSame([], (new DeterministicGuard($snapshot))->check('Это кредит и канцелярит', new Goal('answer_only'), new LeadState)['issues']);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'кредит'])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/prohibitions/check', ['text' => 'кредит'])->assertForbidden();
    }
}
