<?php

namespace App\Seller\Evaluation;

use Illuminate\Database\Query\Builder;

final class EvaluationVisibility
{
    public static function isTestBaseline(?string $simulationId, ?string $comment): bool
    {
        return $comment === ConversationFakeJudge::COMMENT || ($simulationId !== null && $comment === ScenarioFallbackJudge::COMMENT);
    }

    public static function excludeTestBaseline(Builder $query, string $prefix = ''): Builder
    {
        return $query->where(fn (Builder $nested) => $nested->whereNull($prefix.'comment')
            ->orWhere(fn (Builder $notDialogueTest) => $notDialogueTest->where($prefix.'comment', '!=', ConversationFakeJudge::COMMENT)
                ->where(fn (Builder $notSimulationTest) => $notSimulationTest->whereNull($prefix.'sim_run_id')
                    ->orWhere($prefix.'comment', '!=', ScenarioFallbackJudge::COMMENT))));
    }
}
