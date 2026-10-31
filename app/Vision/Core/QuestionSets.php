<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class QuestionSets
{
    public function __construct(private Tenant $tenant) {}

    /**
     * Creates a frozen question set. No commercial question limit is inferred.
     *
     * @param  list<array{id:string,text:string,intent:string}>  $questions
     */
    public function create(#[\SensitiveParameter] array $questions): string
    {
        if (! array_is_list($questions) || $questions === []) {
            throw new InvalidArgumentException('A non-empty question set is required.');
        }
        $ids = [];
        foreach ($questions as $question) {
            if (! is_array($question) || array_diff(array_keys($question), ['id', 'text', 'intent']) !== []
                || ! is_string($question['id'] ?? null) || trim($question['id']) === '' || mb_strlen($question['id']) > 100
                || ! is_string($question['text'] ?? null) || trim($question['text']) === '' || mb_strlen($question['text']) > 4000
                || ! is_string($question['intent'] ?? null) || trim($question['intent']) === '' || mb_strlen($question['intent']) > 100
                || in_array($question['id'], $ids, true)) {
                throw new InvalidArgumentException('Invalid question set.');
            }
            $ids[] = $question['id'];
        }
        $tenant = $this->tenant->current();

        return DB::transaction(function () use ($questions, $tenant): string {
            // Serialize version assignment for one tenant without locking unrelated brands.
            DB::table('brands')->where('id', $tenant['brand_id'])->where('organization_id', $tenant['organization_id'])->lockForUpdate()->first();
            $version = 1 + (int) DB::table('question_sets')->where($tenant)->max('version');
            $id = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $id, ...$tenant, 'version' => $version,
                'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'size' => count($questions), 'checksum' => CanonicalJson::hash($questions),
                'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }
}
