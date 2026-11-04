<?php

namespace App\Vision\Core;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class ConfirmedClarifications
{
    public function read(array $tenant, string $measurement, array $slots): array
    {
        $rows = DB::table('vision_clarification_decisions as d')
            ->join('snapshot_confirmations as c', function ($join): void {
                $join->on('c.id', '=', 'd.snapshot_confirmation_id')->on('c.brand_id', '=', 'd.brand_id')->on('c.organization_id', '=', 'd.organization_id');
            })->join('answer_facts as f', function ($join): void {
                $join->on('f.id', '=', 'd.answer_fact_id')->on('f.snapshot_confirmation_id', '=', 'c.id')->on('f.brand_id', '=', 'c.brand_id')->on('f.organization_id', '=', 'c.organization_id');
            })->where('d.organization_id', $tenant['organization_id'])->where('d.brand_id', $tenant['brand_id'])
            ->where('c.measurement_id', $measurement)->where('d.kind', 'facts')
            ->whereIn('d.decision', ['correct', 'incorrect', 'outdated'])
            ->orderBy('d.id')->get(['d.item_key', 'd.decision', 'd.correction', 'f.id as fact_id', 'f.measurement_answer_id', 'f.fact']);

        return $this->project($rows->all(), $slots);
    }

    public function project(array $rows, array $slots): array
    {
        $byId = collect($slots)->filter(fn ($slot) => is_array($slot) && is_string($slot['id'] ?? null))->keyBy('id');
        $result = [];
        foreach ($rows as $row) {
            $slot = $byId->get($row->measurement_answer_id);
            $saved = is_string($row->fact) ? json_decode($row->fact, true) : null;
            $verified = is_array($slot) ? ($slot['fact'] ?? null) : null;
            if (! is_array($saved) || ! is_array($verified) || ! is_string($saved['value'] ?? null) || trim($saved['value']) === ''
                || ! hash_equals(CanonicalJson::hash($verified), CanonicalJson::hash($saved))
                || ! hash_equals(hash('sha256', 'facts:'.$row->fact_id.':'.$saved['value']), $row->item_key)
                || ! in_array($row->decision, ['correct', 'incorrect', 'outdated'], true)) {
                continue;
            }
            $correction = is_string($row->correction) ? trim($row->correction) : '';
            if ($row->decision !== 'correct' && $correction === '') {
                continue;
            }
            $result[] = ['text' => $saved['value'], 'classification' => $row->decision, 'correction' => $correction ?: null,
                'provider' => is_string($slot['provider'] ?? null) ? $slot['provider'] : '',
                'source' => is_string($verified['source'] ?? null) ? $verified['source'] : null, 'confirmed' => true];
        }

        return $result;
    }
}
