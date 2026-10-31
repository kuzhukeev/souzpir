<?php

namespace Tests\Feature\Vision\Core;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QuestionSetsTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('historicalSizes')]
    public function test_real_question_set_size_and_content_are_saved(int $size): void
    {
        [$organization, $brand] = $this->cabinet();
        $questions = array_map(fn (int $n): array => ['id' => 'q'.$n, 'text' => 'Вопрос '.$n, 'intent' => 'choose'], range(1, $size));
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($size, $questions): void {
            $first = app(QuestionSets::class)->create($questions);
            $second = app(QuestionSets::class)->create($questions);
            $rows = DB::table('question_sets')->whereIn('id', [$first, $second])->orderBy('version')->get();
            self::assertSame([1, 2], $rows->pluck('version')->all());
            self::assertSame($size, $rows[0]->size);
            self::assertSame($questions, json_decode($rows[0]->questions, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($rows[0]->checksum, $rows[1]->checksum);
        });
    }

    public static function historicalSizes(): array
    {
        return [[12], [20], [30]];
    }

    public function test_active_brand_is_required_and_duplicate_questions_are_rejected(): void
    {
        [$organization, $brand] = $this->cabinet();
        $question = ['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose'];
        app(TenantContextRunner::class)->run(new TenantContext($organization, $brand, source: 'job'), function () use ($question): void {
            try {
                app(QuestionSets::class)->create([$question, $question]);
                self::fail('Duplicate question must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('question_sets')->count());
            }
        });
        $this->expectException(\LogicException::class);
        app(QuestionSets::class)->create([$question]);
    }

    private function cabinet(): array
    {
        $organization = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $organization, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $organization, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);

        return [$organization, $brand];
    }
}
