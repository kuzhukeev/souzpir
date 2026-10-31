<?php

use App\Vision\Profile\CompletionRules;
use App\Vision\Profile\QuizLimits;
use Illuminate\Support\Facades\Http;

it('boots the real feature registry and exposes the questionnaire limits', function (): void {
    $limits = QuizLimits::configured();
    expect($limits->values())->toBe(['competitors' => 10, 'facts' => 10, 'questions' => 15])
        ->and($limits->permits('competitors', array_fill(0, 11, 'x')))->toBeFalse()
        ->and($limits->permits('facts', []))->toBeTrue();
});

it('enforces donor completion requirements without network access', function (): void {
    Http::preventStrayRequests();
    $rules = app(CompletionRules::class);
    $answers = [
        'step1' => ['site' => 'https://example.com', 'brand_name' => 'Пример'],
        'step2' => ['aliases' => ['Пример', 'example.com']],
        'step3' => ['category' => 'Товары', 'offering' => 'goods', 'audience' => 'b2c', 'buyer_phrase' => 'где купить товар', 'category_forms' => ['nominative' => 'Товары', 'genitive_plural' => 'товаров']],
        'step4' => ['competitors' => []],
    ];
    $rules->validate($answers);
    expect(true)->toBeTrue();
    foreach (['http://localhost', 'https://example.com/path?token=x', 'https://example.com/#section', 'https://example.lan', 'https://example.arpa'] as $url) {
        $invalid = $answers;
        $invalid['step1']['site'] = $url;
        expect(fn () => $rules->validate($invalid))->toThrow(InvalidArgumentException::class);
    }
    $invalid = $answers;
    $invalid['step2']['aliases'] = ['Пример'];
    expect(fn () => $rules->validate($invalid))->toThrow(InvalidArgumentException::class);
    $invalid = $answers;
    $invalid['step3']['offering'] = '';
    expect(fn () => $rules->validate($invalid))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});
