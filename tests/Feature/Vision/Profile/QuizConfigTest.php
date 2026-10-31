<?php

use App\Vision\Profile\QuizLimits;

it('boots the real feature registry and exposes the questionnaire limits', function (): void {
    $limits = QuizLimits::configured();
    expect($limits->values())->toBe(['competitors' => 10, 'facts' => 10, 'questions' => 15])
        ->and($limits->permits('competitors', array_fill(0, 11, 'x')))->toBeFalse()
        ->and($limits->permits('facts', []))->toBeTrue();
});
