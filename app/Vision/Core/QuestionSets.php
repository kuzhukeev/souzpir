<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use App\Models\Membership;
use App\Tenancy\TenantContext;
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
        if (count($questions) > WorkloadLimits::limit('max_questions')) {
            throw new InvalidArgumentException('Question set exceeds the technical limit.');
        }
        $ids = [];
        foreach ($questions as $question) {
            if (! is_array($question) || array_diff(array_keys($question), ['id', 'text', 'intent']) !== []
                || ! is_string($question['id'] ?? null) || trim($question['id']) === '' || mb_strlen($question['id']) > 100
                || ! is_string($question['text'] ?? null) || trim($question['text']) === '' || mb_strlen($question['text']) > 4000
                || ! is_string($question['intent'] ?? null) || trim($question['intent']) === '' || mb_strlen($question['intent']) > 100
                || ! mb_check_encoding($question['id'], 'UTF-8') || ! mb_check_encoding($question['text'], 'UTF-8') || ! mb_check_encoding($question['intent'], 'UTF-8')
                || in_array($question['id'], $ids, true)) {
                throw new InvalidArgumentException('Invalid question set.');
            }
            $ids[] = $question['id'];
        }
        try {
            $encoded = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            // PostgreSQL JSONB canonicalizes whitespace, key order and escaping.
            // Use the same stored representation that measurement preflight checks.
            $size = DB::selectOne('select octet_length(cast(cast(? as jsonb) as text)) as bytes', [$encoded]);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Question set cannot be safely inspected.');
        }
        if (! $size || ! is_numeric($size->bytes) || (int) $size->bytes > WorkloadLimits::limit('max_questions_bytes')) {
            throw new InvalidArgumentException('Question set exceeds the technical limit.');
        }
        $tenant = $this->tenant->current();
        $context = app(TenantContext::class);

        try {
            return DB::transaction(function () use ($questions, $tenant, $context, $encoded): string {
                // Recheck tenant and membership after acquiring the global lock order.
                $org = DB::table('organizations')->where('id', $tenant['organization_id'])->where('status', 'active')->lockForUpdate()->first();
                $brand = DB::table('brands')->where('id', $tenant['brand_id'])->where('organization_id', $tenant['organization_id'])->where('status', 'active')->lockForUpdate()->first();
                if (! $org || ! $brand || $context->source === 'public') {
                    throw new \LogicException('Vision question set is unavailable.');
                }
                $service = $context->source === 'job' && $context->actorId === null && $context->membershipId === null;
                if (! $service) {
                    $membership = Membership::withoutGlobalScopes()->whereKey($context->membershipId)
                        ->where('organization_id', $tenant['organization_id'])->where('user_id', $context->actorId)
                        ->where('status', 'active')->sharedLock()->first();
                    if (! $context->actorId || ! $context->membershipId || ! $membership || ! $membership->permitsBrand($tenant['brand_id'])) {
                        throw new \LogicException('Vision question set is unavailable.');
                    }
                }
                $version = 1 + (int) DB::table('question_sets')->where($tenant)->max('version');
                $id = (string) Str::ulid();
                DB::table('question_sets')->insert(['id' => $id, ...$tenant, 'version' => $version,
                    'questions' => $encoded,
                    'size' => count($questions), 'checksum' => CanonicalJson::hash($questions),
                    'created_at' => now(), 'updated_at' => now()]);

                return $id;
            });
        } catch (\Throwable) {
            throw new \LogicException('Vision question set is unavailable.');
        }
    }
}
