<?php

namespace App\Seller\Evaluation;

use Illuminate\Database\Query\Builder;

final class EvaluationVisibility
{
    public static function isTestBaseline(?string $simulationId, ?string $comment): bool
    {
        return $comment === ScenarioFallbackJudge::COMMENT;
    }

    public static function excludeTestBaseline(Builder $query, string $prefix = ''): Builder
    {
        return $query->where(fn (Builder $nested) => $nested->whereNull($prefix.'comment')
            ->orWhere($prefix.'comment', '!=', ScenarioFallbackJudge::COMMENT));
    }
}
