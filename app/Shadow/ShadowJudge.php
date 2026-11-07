<?php

namespace App\Shadow;

final class ShadowJudge
{
    /** @return array{0: string, 1: ?string} [verdict, notes] */
    public function compare(string $baselineResponse, string $candidateResponse): array
    {
        if ($this->normalize($baselineResponse) === $this->normalize($candidateResponse)) {
            return ['match', null];
        }

        return ['mismatch', 'Ответ нового движка отличается от ответа прототипа.'];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
