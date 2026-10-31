<?php

namespace App\Vision\Core\Profiles;

use App\Vision\KnowledgeBridge\SelfDeclaredProfile;
use InvalidArgumentException;
use JsonException;

/** Seven data sections; step eight is a review screen, not stored answers. */
final class QuizAnswers
{
    public static function validateStep(int $step, #[\SensitiveParameter] array $value): void
    {
        if ($step < 1 || $step > 7 || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Invalid questionnaire section.');
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE, 32);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid questionnaire section.');
        }
        if (strlen($encoded) > 32000) {
            throw new InvalidArgumentException('Questionnaire section exceeds the technical limit.');
        }
        $bounded = match ($step) {
            4 => ['competitors', 10], 5 => ['facts', 10], 7 => ['questions', 15], default => null
        };
        if ($bounded && array_key_exists($bounded[0], $value)
            && (! is_array($value[$bounded[0]]) || ! array_is_list($value[$bounded[0]]) || count($value[$bounded[0]]) > $bounded[1])) {
            throw new InvalidArgumentException('Questionnaire collection exceeds the technical limit.');
        }
    }

    /** Encode the whole document with the same depth limit used for subsequent reads. */
    public static function encodeDocument(#[\SensitiveParameter] array $answers): string
    {
        try {
            $encoded = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 32);
            json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid questionnaire document.');
        }
        if (strlen($encoded) > 262144) {
            throw new InvalidArgumentException('Questionnaire document exceeds the technical limit.');
        }

        return $encoded;
    }

    public static function validateComplete(#[\SensitiveParameter] array $answers): void
    {
        if (count($answers) !== 7) {
            throw new InvalidArgumentException('Complete all questionnaire sections.');
        }
        for ($step = 1; $step <= 7; $step++) {
            $value = $answers['step'.$step] ?? null;
            if (! is_array($value)) {
                throw new InvalidArgumentException('Complete all questionnaire sections.');
            }
            self::validateStep($step, $value);
        }
        // Submit/edit must never create a version the bridge cannot project. Drafts may remain incomplete.
        SelfDeclaredProfile::projectAnswers($answers);
    }
}
