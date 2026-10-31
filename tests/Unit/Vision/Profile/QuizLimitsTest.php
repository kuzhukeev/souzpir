<?php

use App\Vision\Profile\QuizLimits;

it('accepts empty collections and the confirmed questionnaire limits', function (string $name, int $limit): void {
    $limits = new QuizLimits(['competitors' => 10, 'facts' => 10, 'questions' => 15]);
    expect($limits->permits($name, []))->toBeTrue()
        ->and($limits->permits($name, array_fill(0, $limit, 'item')))->toBeTrue()
        ->and($limits->permits($name, array_fill(0, $limit + 1, 'item')))->toBeFalse();
})->with([['competitors', 10], ['facts', 10], ['questions', 15]]);

it('rejects associative arrays and unknown collections', function (): void {
    $limits = new QuizLimits(['competitors' => 10, 'facts' => 10, 'questions' => 15]);
    expect($limits->permits('facts', ['first' => 'x']))->toBeFalse();
    expect(fn () => $limits->permits('unexpected', []))->toThrow(InvalidArgumentException::class);
});

it('uses explicitly configured limits and rejects invalid settings', function (): void {
    $limits = new QuizLimits(['competitors' => 3, 'facts' => 0, 'questions' => 2]);
    expect($limits->permits('competitors', array_fill(0, 4, 'x')))->toBeFalse()
        ->and($limits->permits('facts', []))->toBeTrue()
        ->and($limits->permits('facts', ['x']))->toBeFalse();
    expect(fn () => new QuizLimits(['competitors' => 10, 'facts' => -1, 'questions' => 15]))->toThrow(InvalidArgumentException::class);
});
