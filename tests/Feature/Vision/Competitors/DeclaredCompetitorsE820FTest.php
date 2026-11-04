<?php

namespace Tests\Feature\Vision\Competitors;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DeclaredCompetitorsE820FTest extends TenancyTestCase
{
    public function test_declared_companies_are_brand_scoped_and_require_quiz_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $quiz = (string) Str::ulid();
            $answers = ['step1' => ['brand_name' => 'Бренд'], 'step2' => ['aliases' => []], 'step3' => ['category' => 'Товары'],
                'step4' => ['competitors' => [['name' => $item->id === $brand->id ? 'Альфа' : 'Чужая', 'site' => 'https://example.test']]],
                'step5' => ['facts' => []], 'step6' => ['presence' => []], 'step7' => ['questions' => []]];
            DB::table('visibility_quizzes')->insert(['id' => $quiz, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => 1,
                'status' => 'submitted', 'submitted_by' => $owner->id, 'submitted_at' => now(), 'answers' => json_encode($answers), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_profiles')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'quiz_id' => $quiz, 'version' => 1, 'source' => 'submission', 'answers' => json_encode($answers),
                'checksum' => CanonicalJson::hash($answers), 'created_by' => $owner->id, 'created_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('declared', 1)->where('declared.0.name', 'Альфа')->where('declared.0.site', 'https://example.test'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('declared.0.name', 'Чужая'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('declared', 0));
        $this->post('/vision/competitors/track', ['name' => 'Альфа', 'tracked' => true])->assertForbidden();
    }
}
