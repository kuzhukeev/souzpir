<?php

namespace App\Vision\Actions;

final class EffectProjection
{
    public function make(array $actions, array $rows): array
    {
        return collect($actions)->map(function ($action) use ($rows): array {
            $date = substr((string) $action->completed_at, 0, 10);
            $before = collect($rows)->filter(fn ($row) => substr($row['date'], 0, 10) < $date)->last();
            $after = collect($rows)->first(fn ($row) => substr($row['date'], 0, 10) > $date);
            $comparable = $before && $after && $before['signature'] !== null && $before['signature'] === $after['signature'] && is_numeric($before['visibility']) && is_numeric($after['visibility']);

            return ['id' => $action->id, 'title' => $action->title, 'completed_at' => $date, 'before' => $comparable ? $before : null, 'after' => $comparable ? $after : null, 'delta' => $comparable ? round($after['visibility'] - $before['visibility'], 2) : null];
        })->all();
    }
}
