<?php

namespace App\Vision\Reports;

use App\Tenancy\TenantContext;
use App\Vision\Core\VisibilityMetrics;
use Illuminate\Support\Facades\DB;

final class ClarificationVisibility
{
    public function apply(TenantContext $tenant, array $payload): array
    {
        $measurement = $payload['measurement_id'];
        $rows = DB::table('vision_clarification_decisions as d')
            ->join('answer_facts as f', function ($join): void {
                $join->on('f.id', '=', 'd.answer_fact_id')->on('f.snapshot_confirmation_id', '=', 'd.snapshot_confirmation_id')
                    ->on('f.organization_id', '=', 'd.organization_id')->on('f.brand_id', '=', 'd.brand_id');
            })
            ->join('snapshot_confirmations as c', function ($join): void {
                $join->on('c.id', '=', 'd.snapshot_confirmation_id')->on('c.organization_id', '=', 'd.organization_id')
                    ->on('c.brand_id', '=', 'd.brand_id');
            })
            ->where('d.organization_id', $tenant->organizationId)->where('d.brand_id', $tenant->brandId)
            ->where('c.measurement_id', $measurement)->where('d.kind', 'mentions')
            ->get(['d.item_key', 'd.decision', 'f.id as fact_id', 'f.measurement_answer_id', 'f.fact']);

        $decisions = [];
        $candidates = [];
        foreach ($rows as $row) {
            $fact = json_decode($row->fact ?? '{}', true);
            if (! is_array($fact)) {
                continue;
            }
            foreach (is_array($fact['identity_candidates'] ?? null) ? $fact['identity_candidates'] : [] as $candidate) {
                $name = is_string($candidate) ? $candidate : ($candidate['name'] ?? null);
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                $key = hash('sha256', 'mentions:'.$row->fact_id.':'.$name);
                $candidates[$row->measurement_answer_id][$key] = true;
                if (hash_equals($key, $row->item_key) && in_array($row->decision, ['yes', 'no'], true)) {
                    $decisions[$row->measurement_answer_id][$key] = $row->decision;
                }
            }
        }

        $slots = $payload['metrics']['slots'] ?? [];
        $applied = 0;
        foreach ($slots as &$slot) {
            $answer = $slot['id'] ?? null;
            $status = $slot['fact']['mention_status'] ?? null;
            if (! is_string($answer) || ! in_array($status, ['possible', 'not_mentioned'], true)
                || ($slot['status'] ?? null) !== 'ok' || ! ($slot['protocol_valid'] ?? false)
                || ! is_bool($slot['fact']['brand_mentioned'] ?? null)) {
                continue;
            }
            $choices = $decisions[$answer] ?? [];
            if (in_array('yes', $choices, true)) {
                $slot['fact']['mention_status'] = 'confirmed';
                $slot['fact']['brand_mentioned'] = true;
                $applied++;
            } elseif ($status === 'possible' && $choices !== [] && count($choices) === count($candidates[$answer] ?? []) && ! in_array('yes', $choices, true)) {
                $slot['fact']['mention_status'] = 'not_mentioned';
                $slot['fact']['brand_mentioned'] = false;
                $applied++;
            }
        }
        unset($slot);

        if ($applied === 0) {
            return ['metrics' => $payload['metrics'], 'applied' => 0];
        }

        $metrics = app(VisibilityMetrics::class)->calculate($slots, array_keys($payload['providers']), count($payload['questions']), $payload['runs']);

        return ['metrics' => $metrics, 'applied' => $applied];
    }
}
