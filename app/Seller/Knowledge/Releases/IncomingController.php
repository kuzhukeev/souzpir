<?php

namespace App\Seller\Knowledge\Releases;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class IncomingController
{
    public function handle(Request $request): mixed
    {
        $input = $request->validate(['type' => ['required', Rule::in(['task', 'review', 'evaluation'])], 'id' => ['required', 'ulid'], 'decision' => ['required', Rule::in(['accept', 'reject', 'merge'])], 'target' => ['sometimes', Rule::in(['fact', 'condition', 'example', 'closed_topic'])], 'version' => ['nullable', 'integer', 'min:1'], 'group' => ['nullable', 'string', 'max:64'], 'text' => ['nullable', 'string', 'max:500'], 'existing_topic_id' => ['required_if:decision,merge', 'nullable', 'string', 'max:100']]);
        app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): void {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $table = ['task' => 'seller_tasks', 'review' => 'message_reviews', 'evaluation' => 'seller_evaluations'][$input['type']];
            $query = DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $input['id']);
            if ($input['type'] === 'task') {
                $query->where('type', 'unknown_question')->where('status', 'new');
            }
            if ($input['type'] === 'review') {
                $query->where('type', 'wrong_fact')->where('status', 'new');
            }
            $query->first() ?? abort(404);
            $processed = DB::table('audit_logs')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('action', 'knowledge.incoming.handled')->where('subject_type', $input['type'])->where('subject_id', $input['id'])->exists();
            abort_if($processed, 404);
            if ($input['decision'] === 'merge') {
                $draft = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
                $data = app(KnowledgeRepository::class)->forRelease($tenant, $draft, true)->toArray();
                abort_unless(collect($data['intents'])->contains(fn (array $topic) => ($topic['id'] ?? null) === $input['existing_topic_id']), 422);
            }
            if ($input['decision'] === 'accept') {
                validator($input, ['version' => ['required', 'integer', 'min:1'], 'group' => ['required', 'string', 'max:64'], 'text' => ['required', 'string', 'max:500']])->validate();
                $draft = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->value('id') ?? abort(404);
                $repo = app(KnowledgeRepository::class);
                $target = $input['target'] ?? 'fact';
                if ($target === 'fact') {
                    $repo->addFact($tenant, $draft, (int) $input['version'], $input['group'], trim($input['text']));
                } else {
                    $data = $repo->forRelease($tenant, $draft, true)->toArray();
                    if ($target === 'condition') {
                        validator($input, ['group' => ['required', Rule::in(['min_order_rub', 'offseason_min_order_rub', 'reserve_days', 'new_client_bonus_pct', 'max_pct_unapproved'])], 'text' => ['required', 'numeric', 'min:0', 'max:100000000']])->validate();
                        if (in_array($input['group'], ['new_client_bonus_pct', 'max_pct_unapproved'], true)) {
                            validator($input, ['text' => ['numeric', 'max:100']])->validate();
                        }
                        $data['conditions'][$input['group']] = 0 + $input['text'];
                    } elseif ($target === 'closed_topic') {
                        $data['closed_topics'][] = ['id' => (string) Str::ulid(), 'category' => trim($input['group']), 'idea' => trim($input['text']), 'next' => 'return'];
                    } else {
                        $data['fewshot']['examples'][] = ['tag' => trim($input['group']), 'text' => trim($input['text']), 'client_type' => 'all', 'confidence' => 0.9, 'source' => 'incoming'];
                    }
                    $repo->saveDraft($tenant, $draft, (int) $input['version'], $data);
                }
            }
            if ($input['type'] !== 'evaluation') {
                $values = ['status' => $input['decision'] === 'reject' ? 'dismissed' : 'done', 'updated_at' => now()];
                if ($input['type'] === 'review' && $input['decision'] === 'accept') {
                    $values['applied_to'] = $input['target'] ?? 'fact';
                }
                $query->update($values);
            }
            app(AuditWriter::class)->record($tenant, new AuditEntry('knowledge.incoming.handled', $input['type'], $input['id'], after: ['decision' => $input['decision'], 'existing_topic_id' => $input['existing_topic_id'] ?? null]));
        });

        return back()->with('status', 'Предложение обработано.');
    }
}
