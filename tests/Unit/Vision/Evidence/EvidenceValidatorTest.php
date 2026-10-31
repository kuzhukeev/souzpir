<?php

namespace Tests\Unit\Vision\Evidence;

use App\Vision\Evidence\ConfirmationValidator;
use App\Vision\Evidence\EntityNormalizer;
use App\Vision\Evidence\EvidenceValidator;
use App\Vision\Evidence\MentionConfirmer;
use App\Vision\Evidence\PositionVerifier;
use App\Vision\Evidence\RecommendationVerifier;
use App\Vision\Evidence\SourceExtractor;
use App\Vision\Evidence\VisibilityProfile;
use Tests\TestCase;

final class EvidenceValidatorTest extends TestCase
{
    public function test_donor_golden_responses_keep_literal_evidence_rules(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/Fixtures/visibility-golden.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('synthetic', $fixtures['kind']);
        self::assertFalse($fixtures['quality_accepted']);
        $validator = app(EvidenceValidator::class);
        foreach ($fixtures['cases'] as $case) {
            $candidate = $validator->deduplicateCompanies($case['expected']);
            $reason = $validator->validationReason($candidate, $case['text'], $case['profile']);
            // Conservative rule declines interpretation when the whole answer is not a direct choice.
            $uncertain = ['openai-1', 'perplexity-1', 'gemini-1', 'claude-1', 'identity-distinctive-two-facts',
                'identity-domain-confirmed', 'methodology-domain-choice', 'methodology-start-with',
                'methodology-first-person-recommended-list', 'methodology-parenthetical-catalog-shortlist'];
            if (in_array($case['id'], $uncertain, true)) {
                self::assertSame('recommendation_evidence', $reason, $case['id']);
            } else {
                self::assertNull($reason, $case['id']);
            }
        }
    }

    public function test_rejects_invented_quotes_reordered_companies_and_attributed_recommendations(): void
    {
        $validator = app(EvidenceValidator::class);
        $profile = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс']];
        $text = 'Рекомендую МеталлСевер. СтальПлюс тоже присутствует в каталоге.';
        $value = ['brand_mentioned' => true, 'brand_position' => 2, 'brand_recommended' => false,
            'mention_quote' => 'СтальПлюс тоже присутствует в каталоге', 'companies' => [
                ['name' => 'МеталлСевер', 'position' => 1, 'role' => 'supplier', 'is_brand' => false],
                ['name' => 'СтальПлюс', 'position' => 2, 'role' => 'supplier', 'is_brand' => true],
            ], 'tone' => 'neutral', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        self::assertNull($validator->validationReason($value, $text, $profile));
        $invented = $value;
        $invented['mention_quote'] = 'СтальПлюс гарантирует скидку';
        self::assertSame('brand_quote', $validator->validationReason($invented, $text, $profile));
        $wrongOrder = $value;
        $wrongOrder['companies'] = array_reverse($wrongOrder['companies']);
        self::assertSame('company_order', $validator->validationReason($wrongOrder, $text, $profile));
        $wrongRecommendation = $value;
        $wrongRecommendation['brand_recommended'] = true;
        $wrongRecommendation['mention_quote'] = $text;
        self::assertSame('recommendation_evidence', $validator->validationReason($wrongRecommendation, $text, $profile));
        $inventedClaim = $value;
        $inventedClaim['claims'] = ['Надёжная доставка'];
        self::assertSame('claim_quote', $validator->validationReason($inventedClaim, $text, $profile));
    }

    public function test_checking_a_supplier_is_not_recommending_the_supplier(): void
    {
        $profile = ['name' => 'СтальПлюс', 'aliases' => ['СтальПлюс']];
        $text = 'Рекомендую тщательно проверить СтальПлюс перед заказом.';
        $value = ['brand_mentioned' => true, 'brand_position' => 1, 'brand_recommended' => true,
            'mention_quote' => $text, 'companies' => [['name' => 'СтальПлюс', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]],
            'tone' => 'positive', 'claims' => [], 'suspected_errors' => [], 'confidence' => 0.9];
        self::assertSame('recommendation_evidence', app(EvidenceValidator::class)->validationReason($value, $text, $profile));
        $verifier = app(RecommendationVerifier::class);
        self::assertFalse($verifier->verifies($text, $text, $profile));
        self::assertTrue($verifier->verifies('Рекомендую СтальПлюс.', 'Рекомендую СтальПлюс', $profile));
        self::assertFalse($verifier->verifies('Рекомендую СтальПлюс, но не покупать без проверки.', 'Рекомендую СтальПлюс', $profile));
        foreach (['Рекомендую СтальПлюс? Скорее наоборот.', 'Рекомендую СтальПлюс: держитесь подальше.', 'Рекомендую СтальПлюс... только врагам.'] as $uncertain) {
            self::assertFalse($verifier->verifies($uncertain, $uncertain, $profile));
            $value['mention_quote'] = $uncertain;
            self::assertSame('recommendation_evidence', app(EvidenceValidator::class)->validationReason($value, $uncertain, $profile));
        }
    }

    public function test_position_is_unknown_when_a_preceding_company_is_omitted(): void
    {
        $verify = app(PositionVerifier::class);
        $text = "1. МеталлСевер — поставщик.\n2. СтальПлюс — поставщик.";
        $partial = [['name' => 'СтальПлюс', 'position' => 1, 'role' => 'supplier', 'is_brand' => true]];
        self::assertFalse($verify->complete($text, $partial));
        $complete = [
            ['name' => 'МеталлСевер', 'position' => 1, 'role' => 'supplier', 'is_brand' => false],
            ['name' => 'СтальПлюс', 'position' => 2, 'role' => 'supplier', 'is_brand' => true],
        ];
        self::assertFalse($verify->complete($text, $complete));
        self::assertTrue($verify->complete("1. МеталлСевер\n2. СтальПлюс", $complete));
    }

    public function test_ambiguous_alias_requires_literal_identity_facts_not_model_assertions(): void
    {
        $validator = app(ConfirmationValidator::class);
        $profile = ['name' => 'Торты', 'aliases' => ['Торты'], 'category' => 'торты',
            'facts' => [
                ['topic' => 'address', 'value' => 'Ленина 15', 'verifiable' => true],
                ['topic' => 'warranty', 'value' => 'гарантия 3 дня', 'verifiable' => true],
            ]];
        $text = 'Торты: Ленина 15, гарантия 3 дня.';
        self::assertSame('unknown', $validator->validate($text, $profile, null)['status']);
        $model = ['decision' => 'confirmed', 'evidence' => $text, 'matched_facts' => ['Ленина 15', 'гарантия 3 дня']];
        self::assertSame('confirmed', $validator->validate($text, $profile, $model)['status']);
        $negated = 'Торты не по адресу Ленина 15; гарантия 3 дня не действует.';
        $negatedModel = ['decision' => 'confirmed', 'evidence' => $negated, 'matched_facts' => ['Ленина 15', 'гарантия 3 дня']];
        self::assertSame('unknown', $validator->validate($negated, $profile, $negatedModel)['status']);
        $inventedQuote = $model;
        $inventedQuote['evidence'] = 'Торты: Ленина 15, гарантия 4 дня.';
        self::assertSame('unknown', $validator->validate($text, $profile, $inventedQuote)['status']);
        $inventedFacts = $model;
        $inventedFacts['matched_facts'] = ['Ленина 15', 'скидка'];
        self::assertSame('unknown', $validator->validate($text, $profile, $inventedFacts)['status']);
        $unknownFacts = $profile;
        $unknownFacts['facts'][1]['verifiable'] = false;
        self::assertSame('unknown', $validator->validate($text, $unknownFacts, $model)['status']);
        self::assertSame('confirmed', $validator->validate('Рекомендую Tort4U.', ['name' => 'Tort4U', 'aliases' => []], null)['status']);
    }

    public function test_sources_are_literal_domain_bound_and_strip_sensitive_query(): void
    {
        $extractor = app(SourceExtractor::class);
        $profile = ['brand_domain' => 'steel.example', 'domains' => ['steel.example'], 'competitors' => [['domain' => 'other.example']]];
        $sources = $extractor->extract(
            'https://shop.steel.example/catalog?token=secret#private https://steel.example.evil.test/ https://other.example/a http://127.0.0.1/private https://user:pass@steel.example/',
            $profile
        );
        self::assertCount(3, $sources);
        self::assertSame('https://shop.steel.example/catalog', $sources[0]['url']);
        self::assertSame('brand_site', $sources[0]['source_type']);
        self::assertSame('other', $sources[1]['source_type']);
        self::assertSame('competitor_site', $sources[2]['source_type']);
        self::assertStringNotContainsString('secret', json_encode($sources, JSON_THROW_ON_ERROR));
        $platforms = $extractor->extract('https://yandex.ru/maps/123 https://yandex.ru/mapsevil https://ozon.ru/a https://profi.ru.evil.test/', $profile);
        self::assertSame(['maps', 'other', 'marketplace', 'other'], array_column($platforms, 'source_type'));
    }

    public function test_alias_matching_does_not_confuse_domains_or_generic_category(): void
    {
        $confirmer = app(MentionConfirmer::class);
        $profile = ['name' => 'Tort4U', 'aliases' => ['торты'], 'brand_domain' => 'tort4u.ru', 'category' => 'торты'];
        self::assertContains('tort4u', VisibilityProfile::recognitionAliases($profile));
        self::assertTrue($confirmer->isAmbiguous('торты', $profile));
        self::assertTrue($confirmer->contains('Tort4U поставляет торты.', 'Tort4U'));
        self::assertFalse($confirmer->contains('not-tort4u.example', 'tort4u'));
        self::assertSame('стальплюс', app(EntityNormalizer::class)->normalize('ООО «СтальПлюс»'));
    }
}
