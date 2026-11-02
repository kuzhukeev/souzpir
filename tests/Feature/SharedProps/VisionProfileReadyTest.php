<?php

namespace Tests\Feature\SharedProps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Tests\Feature\Tenancy\TenancyTestCase;

final class VisionProfileReadyTest extends TenancyTestCase
{
    public function test_readiness_is_visible_to_viewer_and_scoped_to_selected_brand(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/vision-readiness-test', fn () => Inertia::render('Testing/Shared'));
        [$owner, $org, $first, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $quiz = (string) Str::ulid();
        $profile = (string) Str::ulid();
        DB::table('visibility_quizzes')->insert(['id' => $quiz, 'organization_id' => $org->id, 'brand_id' => $other->id, 'version' => 1, 'revision' => 1, 'status' => 'submitted', 'current_step' => 8, 'answers' => '{}', 'submitted_by' => $owner->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_profiles')->insert(['id' => $profile, 'organization_id' => $org->id, 'brand_id' => $other->id, 'quiz_id' => $quiz, 'version' => 1, 'source' => 'submission', 'answers' => '{}', 'checksum' => str_repeat('a', 64), 'created_by' => $owner->id, 'created_at' => now()]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/vision-readiness-test')->assertOk()->assertInertia(fn ($page) => $page->where('visionProfileReady', false));
        $firstQuiz = (string) Str::ulid();
        DB::table('visibility_quizzes')->insert(['id' => $firstQuiz, 'organization_id' => $org->id, 'brand_id' => $first->id, 'version' => 1, 'revision' => 1, 'status' => 'submitted', 'current_step' => 8, 'answers' => '{}', 'submitted_by' => $owner->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_profiles')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $first->id, 'quiz_id' => $firstQuiz, 'version' => 1, 'source' => 'submission', 'answers' => '{}', 'checksum' => str_repeat('a', 64), 'created_by' => $owner->id, 'created_at' => now()]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/vision-readiness-test')->assertOk()->assertInertia(fn ($page) => $page->where('visionProfileReady', true));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision-readiness-test')->assertOk()->assertInertia(fn ($page) => $page->where('visionProfileReady', true));
    }
}
