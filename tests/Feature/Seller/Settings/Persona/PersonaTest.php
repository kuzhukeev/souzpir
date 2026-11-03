<?php

namespace Tests\Feature\Seller\Settings\Persona;

use App\Ai\Protected\ResponderProjection;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PersonaTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function setupDraft(TenantContext $tenant): array
    {
        $persona = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/persona.yaml'));
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = $persona;
        $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));

        return [$draft, $persona];
    }

    private function input(array $persona, int $version = 1): array
    {
        return ['version' => $version, 'name' => $persona['name'], 'intro' => $persona['intro'], 'tone' => $persona['tone'], 'form_bans' => $persona['form_bans'],
            'target_min' => $persona['length']['target_words'][0], 'target_max' => $persona['length']['target_words'][1], 'max_words' => $persona['length']['max_words'], 'max_questions' => $persona['length']['max_questions']];
    }

    public function test_default_values_match_prototype_and_published_prompt_uses_changes(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        [$draft, $persona] = $this->setupDraft($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/persona')->assertOk()->assertInertia(fn ($page) => $page->where('persona.name', $persona['name'])->where('persona.length.max_words', $persona['length']['max_words']));
        $this->post('/seller/persona', $this->input($persona))->assertRedirect()->assertSessionHasNoErrors();
        app(TenantContextRunner::class)->run($tenant, fn () => $this->assertEquals($persona, app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true)->persona()));
        $input = $this->input($persona, 2);
        $input['name'] = 'Мария';
        $input['tone'] = ['Доброжелательно и кратко'];
        $input['max_words'] = 80;
        $this->post('/seller/persona', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => 3])->assertRedirect();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $snapshot = app(KnowledgeRepository::class)->published($tenant);
            $prompt = app(ResponderProjection::class)->render($snapshot, new LeadState, new Goal('answer_only'), $snapshot->persona()['name']);
            $this->assertStringContainsString('Мария', $prompt);
            $this->assertStringContainsString('Доброжелательно и кратко', $prompt);
            $this->assertStringContainsString('80', $prompt);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/persona')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null));
    }

    public function test_validation_version_and_permissions(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        [, $persona] = $this->setupDraft($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = $this->input($persona);
        $input['name'] = '';
        $this->post('/seller/persona', $input)->assertSessionHasErrors('name');
        $input = $this->input($persona);
        $input['target_max'] = 150;
        $this->post('/seller/persona', $input)->assertSessionHasErrors('target_max');
        $input = $this->input($persona);
        $input['version'] = 9;
        $this->post('/seller/persona', $input)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/persona')->assertForbidden();
        $this->post('/seller/persona', $this->input($persona))->assertForbidden();
    }

    public function test_profile_and_service_phrases_are_scoped_and_validated(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        [$draft, $persona] = $this->setupDraft($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $profile = $this->input($persona);
        $profile += ['role' => 'Консультант', 'bot_disclosure' => 'if_asked', 'address' => 'vy',
            'anchors' => ['Помогает выбрать'], 'stop_phrases' => ['точно завтра'],
            'hard_bans' => ['Не обещать скидку'], 'max_sentences' => 4];
        $profile['form_bans'] = ['обращение на ты'];
        $this->post('/seller/persona', $profile)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/persona')->assertInertia(fn ($page) => $page->where('persona.role', 'Консультант')
            ->where('persona.address', 'vy')->where('persona.length.max_sentences', 4));
        $saved = app(TenantContextRunner::class)->run($tenant,
            fn () => app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true)->persona());
        $this->assertContains('обращение на ты', $saved['stop_phrases']);
        $prompt = app(TenantContextRunner::class)->run($tenant, fn () => app(ResponderProjection::class)->render(
            app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true), new LeadState,
            new Goal('answer_only'), $saved['name']));
        $this->assertStringContainsString($profile['intro'], $prompt);
        $this->assertStringContainsString('обращение на ты', $prompt);
        $this->assertStringContainsString('Обращайся к клиенту на Вы', $prompt);
        $this->assertStringContainsString('Якорь персонажа: Помогает выбрать', $prompt);

        $phrases = ['error' => 'Уточню и вернусь.', 'handoff' => 'Передам менеджеру.',
            'out_of_hours' => '', 'no_answer' => '', 'do_not_disturb' => '', 'reminder' => '', 'goodbye' => 'До связи!'];
        $this->post('/seller/persona/phrases', ['version' => 2, 'phrases' => $phrases,
            'quick_replies' => ['Спасибо за вопрос']])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/persona')->assertInertia(fn ($page) => $page->where('phrases.goodbye', 'До связи!')
            ->where('quickReplies.0', 'Спасибо за вопрос'));
        $this->post('/seller/persona/phrases', ['version' => 3, 'phrases' => ['bad' => 'x'],
            'quick_replies' => []])->assertSessionHasErrors('phrases');
        $this->post('/seller/persona', [...$profile, 'version' => 3, 'bot_disclosure' => 'never'])->assertSessionHasErrors('bot_disclosure');

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/persona/phrases', ['version' => 3, 'phrases' => $phrases,
            'quick_replies' => []])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/persona')->assertInertia(fn ($page) => $page->where('draft', null));
        $this->post('/seller/persona/phrases', ['version' => 3, 'phrases' => $phrases,
            'quick_replies' => []])->assertNotFound();
    }
}
