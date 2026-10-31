<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ResponderMessages;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ResponderPrototypeGoldenTest extends TestCase
{
    public function test_synthetic_cases_match_captured_prototype_messages_and_tool_availability(): void
    {
        // Captured from the unmodified prototype Responder::respond with an in-memory LlmClient,
        // a fixed system prompt and synthetic inputs; no model or tools were executed.
        // Prototype Responder.php SHA-256: 37529a757c0b76ad0f2b5e1f1ab73e951e539ed986d322d461edf718bc4ea3c5.
        $json = file_get_contents(__DIR__.'/fixtures/responder-prototype-synthetic.json');
        self::assertIsString($json);
        $cases = $this->normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        self::assertCount(4, $cases);

        foreach ($cases as $case) {
            $actual = (new ResponderMessages)->compose(
                'СИСТЕМНЫЙ ШАБЛОН',
                $case['history'],
                $case['allowedTools'],
                $case['toolResults'],
                $case['guardIssues'],
                $case['historyTurns'],
            );
            $projection = [
                'messages' => $actual['messages'],
                'tools' => array_column(array_column($actual['tools'], 'function'), 'name'),
                'toolChoice' => $actual['toolChoice'],
            ];
            self::assertSame($case['expected'], $projection, $case['name']);
            self::assertSame('not_authorization', $actual['status']);
        }
    }

    /** Preserve JSON's empty objects while mapping non-empty fixture objects to PHP arrays. */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            if ($fields === []) {
                return $value;
            }

            return array_map($this->normalize(...), $fields);
        }

        return is_array($value) ? array_map($this->normalize(...), $value) : $value;
    }
}
