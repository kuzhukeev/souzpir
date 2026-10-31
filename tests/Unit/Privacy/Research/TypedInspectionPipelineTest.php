<?php

namespace Tests\Unit\Privacy\Research;

use App\Ai\ContactMasker;
use App\Ai\SchemaObjects;
use App\Privacy\ContactBoundary;
use App\Privacy\InspectionOutcome;
use App\Privacy\Secrets\SecretBoundary;
use PHPUnit\Framework\TestCase;

/** Synthetic composition of the existing outbound stages; no Gateway or transport. */
final class TypedInspectionPipelineTest extends TestCase
{
    public function test_schema_masking_and_typed_boundaries_preserve_objects_without_contact_leak(): void
    {
        $body = ['messages' => [['role' => 'user', 'content' => 'Ответьте synthetic-person@example.test']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'sample', 'parameters' => [
                'type' => 'object', 'properties' => new \stdClass, 'required' => [],
            ]]]]];
        $secret = new SecretBoundary;
        $contact = new ContactBoundary;
        self::assertSame(InspectionOutcome::Clear, $secret->inspectTyped(SchemaObjects::forInspection($body)));
        $masked = (new ContactMasker)->request($body);
        self::assertStringNotContainsString('synthetic-person@example.test', json_encode($masked, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('[EMAIL]', $masked['messages'][0]['content']);
        self::assertInstanceOf(\stdClass::class, $masked['tools'][0]['function']['parameters']['properties']);
        $inspection = SchemaObjects::forInspection($masked);
        self::assertSame(InspectionOutcome::Clear, $secret->inspectTyped($inspection));
        self::assertSame(InspectionOutcome::Clear, $contact->inspectTyped($inspection));
    }

    public function test_false_positive_requires_review_without_claiming_a_real_contact(): void
    {
        $body = ['messages' => [['role' => 'user', 'content' => 'Знак @ в синтетическом примере']]];
        $masked = (new ContactMasker)->request($body);
        self::assertSame(InspectionOutcome::ReviewRequired, (new ContactBoundary)->inspectTyped(SchemaObjects::forInspection($masked)));
    }
}
