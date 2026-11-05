<?php

namespace App\Seller\Evaluation;

use Illuminate\Database\Query\Builder;

final class EvaluationVisibility
{
    public static function isTestBaseline(?string $simulationId, ?string $comment): bool
    {
        return $simulationId !== null && $comment === ScenarioFallbackJudge::COMMENT;
    }

    public static function excludeTestBaseline(Builder $query, string $prefix = ''): Builder
    {
        return $query->where(fn (Builder $nested) => $nested->whereNull($prefix.'sim_run_id')
            ->orWhereNull($prefix.'comment')->orWhere($prefix.'comment', '!=', ScenarioFallbackJudge::COMMENT));
    }
}
