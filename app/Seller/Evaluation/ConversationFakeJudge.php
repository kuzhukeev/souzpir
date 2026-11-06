<?php

namespace App\Seller\Evaluation;

use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConversationFakeJudge
{
    public function evaluate(TenantContext $tenant, string $conversationId): string
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake') {
            throw ValidationException::withMessages(['evaluation' => 'Тестовая оценка сейчас недоступна.']);
        }
        $scope = ['organization_id' => $tenant->requireOrganizationId(), 'brand_id' => $tenant->brandId];
        $conversation = DB::table('conversations')->where($scope)->where('id', $conversationId)->first() ?? abort(404);
        $existing = DB::table('seller_evaluations')->where($scope)->where('conversation_id', $conversationId)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        if ($existing) {
            return $existing;
        }
        $messages = DB::table('messages')->where($scope)->where('conversation_id', $conversationId)->whereIn('role', ['client', 'agent'])->orderBy('created_at')->orderBy('id')->get(['role', 'text']);
        $turns = [];
        foreach ($messages as $message) {
            if (! is_string($message->text) || trim($message->text) === '') {
                continue;
            }
            $turns[] = [$message->role === 'client' ? 'client' : 'agent' => $message->text, 'meta' => []];
        }
        if ($turns === [] || ! $messages->contains(fn ($message) => $message->role === 'agent' && is_string($message->text) && trim($message->text) !== '')) {
            throw ValidationException::withMessages(['evaluation' => 'Для тестовой оценки нужен хотя бы один ответ продавца.']);
        }
        $supervisor = app(Supervisor::class);
        try {
            $response = app(FakeDriver::class)->complete(app(Judge::class)->request($turns, []));
            $result = json_decode($response['content'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new \LogicException('Invalid fake judge result.');
            }
            $normal = $supervisor->normalize($result, count($turns));
        } catch (Rejected $error) {
            if (! str_starts_with($error->getMessage(), 'AI fixture missing for normalized request ')) {
                throw $error;
            }
            $normal = $supervisor->normalize([...array_fill_keys(Supervisor::SCORES, 3), 'comment' => ScenarioFallbackJudge::COMMENT, 'issues' => []], count($turns));
        }
        $id = (string) Str::ulid();
        DB::transaction(function () use ($tenant, $scope, $conversation, $id, $normal): void {
            DB::table('seller_evaluations')->insert($scope + ['id' => $id, 'conversation_id' => $conversation->id, 'scores' => json_encode($normal['scores'], JSON_THROW_ON_ERROR), 'issues' => json_encode($normal['issues'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'average' => $normal['average'], 'comment' => ScenarioFallbackJudge::COMMENT, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($tenant, new AuditEntry('seller.evaluation.created', 'seller_evaluation', $id, after: ['conversation_id' => $conversation->id, 'test_baseline' => true]));
        });

        return $id;
    }
}
