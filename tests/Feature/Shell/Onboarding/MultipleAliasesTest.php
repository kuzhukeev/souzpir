<?php

namespace Tests\Feature\Shell\Onboarding;

use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MultipleAliasesTest extends TenancyTestCase
{
    public function test_multiple_aliases_are_saved_and_owner_access_is_required(): void
    {
        $owner = $this->user();
        $org = Organization::create(['name' => 'Черновик', 'owner_user_id' => $owner->id]);
        $this->within($org, null, fn () => Membership::create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']));
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $form = ['organization_name' => 'Компания', 'industry' => 'Пиротехника', 'region' => 'Москва', 'timezone' => 'Europe/Moscow', 'website' => 'https://example.test', 'brand_name' => 'Бренд', 'alias' => "Один\nДва", 'theme' => 'pyro', 'seller' => true, 'vision' => false, 'step' => 2];
        $this->actingAs($owner)->post('/start/draft', $form)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/start')->assertInertia(fn ($page) => $page->where('initial.alias', "Один\nДва"));
        $bad = $form;
        $bad['alias'] = str_repeat('a', 121);
        $this->post('/start/draft', $bad)->assertSessionHasErrors('alias');
        $bad = $form;
        $bad['website'] = 'не адрес';
        $this->post('/start/draft', $bad)->assertSessionHasErrors('website');
        $form['step'] = 4;
        $this->post('/start', $form)->assertRedirect()->assertSessionHasNoErrors();
        $settings = json_decode(DB::table('brands')->where('organization_id', $org->id)->value('settings'), true);
        $this->assertSame(['Один', 'Два'], $settings['onboarding']['aliases']);
        [$viewer] = $this->member($org, 'viewer');
        $this->actingAs($viewer)->get('/start')->assertForbidden();
    }
}
