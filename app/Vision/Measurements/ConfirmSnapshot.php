<?php

namespace App\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ConfirmSnapshot
{
    public function run(Request $request, string $measurement): bool
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $context) use ($measurement): bool {
            Gate::authorize('vision.measure.run');
            $plan = DB::table('measurements')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $measurement)->lockForUpdate()->first();
            abort_unless($plan, 404);
            if ($plan->status !== 'completed') {
                throw ValidationException::withMessages(['measurement' => 'Подтвердить можно только завершённый замер.']);
            }
            try {
                $profile = json_decode($plan->profile_snapshot, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($profile) || ! is_string($plan->profile_checksum)
                    || ! hash_equals($plan->profile_checksum, CanonicalJson::hash($profile))) {
                    throw new \LogicException;
                }
                app(KnowledgeGate::class)->assertAllowed($context, $measurement, $profile);
            } catch (Throwable) {
                throw ValidationException::withMessages(['measurement' => 'Данные замера недоступны для подтверждения.']);
            }
            $answers = DB::table('measurement_answers')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $measurement)
                ->orderBy('id')->get(['id', 'status', 'answer_text', 'fact']);
            if ($answers->isEmpty() || $answers->contains(fn ($answer) => $answer->status !== 'ok')) {
                throw ValidationException::withMessages(['measurement' => 'Ответы замера ещё не готовы.']);
            }
            if (DB::table('snapshot_confirmations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('measurement_id', $measurement)->exists()) {
                return false;
            }
            $id = (string) Str::ulid();
            DB::table('snapshot_confirmations')->insert(['id' => $id, 'organization_id' => $context->organizationId,
                'brand_id' => $context->brandId, 'measurement_id' => $measurement, 'confirmed_by' => $context->actorId,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($answers as $answer) {
                DB::table('answer_facts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $context->organizationId,
                    'brand_id' => $context->brandId, 'snapshot_confirmation_id' => $id, 'measurement_answer_id' => $answer->id,
                    'answer_text' => $answer->answer_text, 'fact' => $answer->fact, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditWriter::class)->record($context, new AuditEntry('vision.measurement.confirmed', 'measurement', $measurement));

            return true;
        });
    }
}
