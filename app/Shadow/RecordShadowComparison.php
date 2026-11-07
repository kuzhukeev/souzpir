<?php

namespace App\Shadow;

use Illuminate\Support\Facades\DB;

final class RecordShadowComparison
{
    public function record(string $channel, string $inboundText, string $baselineResponse, string $candidateResponse): ShadowComparison
    {
        return DB::transaction(function () use ($channel, $inboundText, $baselineResponse, $candidateResponse): ShadowComparison {
            [$verdict, $notes] = app(ShadowJudge::class)->compare($baselineResponse, $candidateResponse);

            return ShadowComparison::create([
                'channel' => $channel,
                'inbound_text' => $inboundText,
                'baseline_response' => $baselineResponse,
                'candidate_response' => $candidateResponse,
                'verdict' => $verdict,
                'notes' => $notes,
            ]);
        });
    }
}
