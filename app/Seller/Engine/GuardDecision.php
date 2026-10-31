<?php

namespace App\Seller\Engine;

use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/** Pure decision only: inputs are untrusted and this cannot authorize an AI call or send. */
final class GuardDecision
{
    private const MODEL_GOALS = ['answer_and_ask', 'handle_objection', 'unknown_fact', 'closed_topic'];

    private const MAX_LIST_ITEMS = 1000;

    private const MAX_TEXT_BYTES = 262144;

    public function requiresModel(#[SensitiveParameter] array $deterministic, #[SensitiveParameter] Goal $goal, bool $enabled): bool
    {
        try {
            $this->validateDeterministic($deterministic, $goal);

            return $enabled && $deterministic['issues'] === [] && in_array($goal->kind, self::MODEL_GOALS, true);
        } catch (Throwable) {
            throw new InvalidArgumentException('Guard decision could not be safely prepared.');
        }
    }

    /** @return array{status:string,verdict:?string,issues:list<string>,warnings:list<string>,words:int,questions:int} */
    public function finish(#[SensitiveParameter] array $deterministic, #[SensitiveParameter] Goal $goal, bool $enabled, #[SensitiveParameter] ?array $model = null): array
    {
        try {
            $required = $this->requiresModel($deterministic, $goal, $enabled);
            $base = [
                'status' => 'completed', 'verdict' => $deterministic['verdict'], 'issues' => $deterministic['issues'],
                'warnings' => $deterministic['warnings'], 'words' => $deterministic['words'], 'questions' => $deterministic['questions'],
            ];
            if (! $required) {
                if ($model !== null) {
                    throw new InvalidArgumentException;
                }

                return $base;
            }
            if ($model === null) {
                return [...$base, 'status' => 'requires_model', 'verdict' => null];
            }
            $this->validateModel($model);
            if ($model['status'] !== 'evaluated') {
                return [...$base, 'status' => $model['status'], 'verdict' => null];
            }
            if ($model['verdict'] === false && $model['issues'] === []) {
                // The donor would call this ok; an unexplained negative result is not a pass.
                return [...$base, 'status' => 'unavailable', 'verdict' => null];
            }
            if ($model['verdict'] === false) {
                return [...$base, 'verdict' => 'regenerate', 'issues' => $model['issues']];
            }

            return $base;
        } catch (Throwable) {
            throw new InvalidArgumentException('Guard decision could not be safely prepared.');
        }
    }

    private function validateDeterministic(#[SensitiveParameter] array $value, #[SensitiveParameter] Goal $goal): void
    {
        if (count($value) !== 5 || array_diff(array_keys($value), ['verdict', 'issues', 'warnings', 'words', 'questions']) !== []
            || ! in_array($value['verdict'] ?? null, ['ok', 'regenerate'], true)
            || ! is_int($value['words'] ?? null) || $value['words'] < 0
            || ! is_int($value['questions'] ?? null) || $value['questions'] < 0
            || $goal->kind === '' || strlen($goal->kind) > 128 || ! mb_check_encoding($goal->kind, 'UTF-8')) {
            throw new InvalidArgumentException;
        }
        $bytes = 0;
        $this->validateStrings($value['issues'] ?? null, $bytes);
        $this->validateStrings($value['warnings'] ?? null, $bytes);
        if (($value['issues'] === []) !== ($value['verdict'] === 'ok')) {
            throw new InvalidArgumentException;
        }
    }

    private function validateModel(#[SensitiveParameter] array $value): void
    {
        if (count($value) !== 3 || array_diff(array_keys($value), ['status', 'verdict', 'issues']) !== []
            || ! in_array($value['status'] ?? null, ['evaluated', 'blocked', 'unavailable'], true)) {
            throw new InvalidArgumentException;
        }
        $bytes = 0;
        $this->validateStrings($value['issues'] ?? null, $bytes);
        if (count($value['issues']) > 3
            || ($value['status'] === 'evaluated' && (! is_bool($value['verdict'] ?? null) || ($value['verdict'] === true && $value['issues'] !== [])))
            || ($value['status'] !== 'evaluated' && (($value['verdict'] ?? null) !== null || $value['issues'] !== []))) {
            throw new InvalidArgumentException;
        }
        if (! array_key_exists('verdict', $value)) {
            throw new InvalidArgumentException;
        }
    }

    private function validateStrings(#[SensitiveParameter] mixed $value, int &$bytes): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_LIST_ITEMS) {
            throw new InvalidArgumentException;
        }
        foreach ($value as $text) {
            if (! is_string($text)) {
                throw new InvalidArgumentException;
            }
            $bytes += strlen($text);
            if ($bytes > self::MAX_TEXT_BYTES || ! mb_check_encoding($text, 'UTF-8')) {
                throw new InvalidArgumentException;
            }
        }
    }
}
