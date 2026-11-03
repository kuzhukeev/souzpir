<?php

namespace Tests\Feature\Seller\Quality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EvaluationFeedbackTest extends TenancyTestCase
{
    public function test_feedback_is_persisted_and_scoped_by_brand_and_permission(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $ids = [];
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::ulid();
            $ids[] = $id;
            DB::table('seller_evaluations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'scores' => '{}', 'issues' => '[]', 'average' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/seller/quality/evaluations/'.$ids[0].'/disagree';
        $this->post($url, ['rating' => 2, 'comment' => 'Не учтён контекст'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('judge_calibrations', ['evaluation_id' => $ids[0], 'user_id' => $owner->id, 'rating' => 2, 'comment' => 'Не учтён контекст']);
        $this->post($url, ['rating' => 4, 'comment' => 'Исправлено'])->assertRedirect();
        $this->assertDatabaseCount('judge_calibrations', 1);
        $this->get('/seller/quality/'.now($brand->timezone ?: 'UTC')->toDateString())->assertOk()->assertInertia(fn ($page) => $page->where('evaluations.0.feedback.rating', 4)->where('evaluations.0.feedback.comment', 'Исправлено'));
        $this->post($url, ['rating' => 9, 'comment' => 'Ошибка'])->assertSessionHasErrors('rating');
        $this->post($url, ['rating' => 3, 'comment' => ''])->assertSessionHasErrors('comment');
        $this->post('/seller/quality/evaluations/'.$ids[1].'/disagree', ['rating' => 3, 'comment' => 'Чужая'])->assertNotFound();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->post($url, ['rating' => 3, 'comment' => 'Нет прав'])->assertForbidden();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, ['rating' => 3, 'comment' => 'Нет прав'])->assertForbidden();
    }
}
