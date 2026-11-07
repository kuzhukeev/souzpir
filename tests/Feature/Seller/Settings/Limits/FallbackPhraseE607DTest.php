<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Inertia\Testing\AssertableInertia as Assert;

final class FallbackPhraseE607DTest extends LimitsTestCase
{
    public function test_exhaustion_phrase_comes_from_published_persona_and_falls_back_to_default(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('fallbackPhrase', 'Передаю менеджеру, он напишет сегодня до конца дня.'));

        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['persona'] = ['service_phrases' => ['handoff' => 'Передаю Марии']];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('fallbackPhrase', 'Передаю Марии'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/settings/limits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('fallbackPhrase', 'Передаю менеджеру, он напишет сегодня до конца дня.'));
    }
}
