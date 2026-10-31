<?php

namespace Tests\Unit\Privacy;

use App\Ai\ContactMasker;
use App\Privacy\ContactBoundary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactBoundaryTest extends TestCase
{
    private function request(mixed $text): array
    {
        return ['messages' => [['role' => 'user', 'content' => $text]]];
    }

    #[DataProvider('ordinaryMessages')]
    public function test_ordinary_text_and_legacy_masked_contacts_remain_usable(string $text, ?string $marker): void
    {
        $body = (new ContactMasker)->request($this->request($text));
        $before = $body;
        (new ContactBoundary)->assertSafe($body);
        self::assertSame($before, $body);
        if ($marker !== null) {
            self::assertStringContainsString($marker, $body['messages'][0]['content']);
        } else {
            self::assertSame($text, $body['messages'][0]['content']);
        }
    }

    public static function ordinaryMessages(): array
    {
        return [
            ['Добрый день! У вас есть фейерверки для магазина?', null],
            ['Заказ на 31.10.2026, 3 коробки по 1200 рублей.', null],
            ['Доставка at 14:00, товар доступен.', null],
            ['Доставка: 14:00; количество: 2, 3 или 5.', null],
            ['Варианты: красный; зелёный; синий.', null],
            ['Оплата 1:2:3; готово.', null],
            ['Время работы: 09:00; 10:00; 11:00; 12:00', null],
            ['Цена 1500; 2500; 3500 рублей', null],
            ['Электронная почта user@example.test', '[EMAIL]'],
            ['Почта first+tag@example.test', '[EMAIL]'],
            ['Адрес тест@пример.рф', '[EMAIL]'],
            ['Телефон +7 (999) 123-45-67', '[ТЕЛЕФОН]'],
            ['{"email":"user@example.test"}', '[EMAIL]'],
            ['Международный текст café и эмодзи 🎉', null],
            ['Праздник \\uD83C\\uDF89', null],
            ['Название: Ｆｉｒｅ ✨', null],
        ];
    }

    #[DataProvider('obfuscatedContacts')]
    public function test_ambiguous_or_encoded_contacts_are_rejected_before_driver(string $input): void
    {
        try {
            $body = (new ContactMasker)->request($this->request($input));
            (new ContactBoundary)->assertSafe($body);
            self::fail('An unmasked synthetic contact passed the boundary.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString($input, $e->getMessage());
        }
    }

    public static function obfuscatedContacts(): array
    {
        return [
            ['"first last"@example.test'],
            ['user@[127.0.0.1]'],
            ['first @ example.test'],
            ['first&#64;example.test'],
            ['first&amp;#64;example.test'],
            ['first%40example.test'],
            ['first%2540example.test'],
            ['first＠example.test'],
            ['first(at)example.test'],
            ['first at example dot test'],
            ['user at example.test'],
            ['user at example . test'],
            ['user at example. test'],
            ['user at example .test'],
            ['7;999;123;45;67'],
            ['8:999:123:45:67'],
            ['user at example [dot] test'],
            ['user at example (dot) test'],
            ['user at example [.] test'],
            ['+7;999;123;45;67'],
            ['+7:999:123:45:67'],
            ['user AT example.test'],
            ['user собака example.ru'],
            ['user at example.co.uk'],
            ['+7|999|123|45|67'],
            ['+7,999,123,45,67'],
            ['почта first собака example точка test'],
            ['+7 999<b>123</b>45 67'],
            ["те\u{200B}лефон: 123-45-67"],
            ["+7\u{00A0}999\u{00A0}123\u{00A0}45\u{00A0}67"],
            ["+7\u{202F}999\u{202F}123\u{202F}45\u{202F}67"],
            ["+7 999 123\u{2011}45\u{2011}67"],
            ['+7 999/123/45/67'],
            ['Телефон: 123-45-67'],
            ['tel_1234567'],
            ['Звоните 123-45-67'],
            ['＋７９９９１２３４５６７'],
            ['user\\u0040example.test'],
            ['user\\\\u0040example.test'],
            ['user\\x40example.test'],
            ['user\\100example.test'],
            ['user\\U00000040example.test'],
            ['Напишите @contact_handle'],
        ];
    }

    public function test_nested_arrays_tool_arguments_and_split_chunks_are_checked(): void
    {
        $requests = [
            $this->request(['phone' => "+7\u{00A0}999\u{00A0}123\u{00A0}45\u{00A0}67"]),
            $this->request(['first' => '+7 999', 'second' => '123 45 67']),
            $this->request(['phone1' => 123, 'phone2' => 4567]),
            $this->request('{"phone1":123,"phone2":4567}'),
            ['messages' => [['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'tool1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"first":"+7 999","second":"123 45 67"}']],
            ]]]],
            ['messages' => [
                ['role' => 'user', 'content' => '+7 999'],
                ['role' => 'user', 'content' => '123 45 67'],
            ]],
        ];
        foreach ($requests as $request) {
            try {
                $body = (new ContactMasker)->request($request);
                (new ContactBoundary)->assertSafe($body);
                self::fail('A nested synthetic contact passed the boundary.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unsafe_semantic_identifiers_are_rejected_without_replacement(): void
    {
        $request = ['messages' => [['role' => 'user', 'content' => 'ok']], 'tools' => [['name' => 'first＠example.test']]];
        $body = (new ContactMasker)->request($request);
        try {
            (new ContactBoundary)->assertSafe($body);
            self::fail('Ambiguous identifier passed the boundary.');
        } catch (InvalidArgumentException) {
            self::assertSame($request, $body);
        }
    }

    public function test_invalid_utf8_objects_and_deep_nesting_fail_closed(): void
    {
        foreach ([$this->request(chr(255)), $this->request(new \stdClass)] as $input) {
            try {
                (new ContactBoundary)->assertSafe($input);
                self::fail('Unsafe data passed the boundary.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $nested = 'safe';
        for ($i = 0; $i < 30; $i++) {
            $nested = [$nested];
        }
        $this->expectException(InvalidArgumentException::class);
        (new ContactBoundary)->assertSafe($this->request($nested));
    }

    public function test_nested_unicode_escape_is_not_interpreted_only_once(): void
    {
        $body = $this->request('user%255Cu0040example.test');
        $this->expectException(InvalidArgumentException::class);
        (new ContactBoundary)->assertSafe($body);
    }

    public function test_malformed_large_numeric_keys_and_oversized_inputs_fail_closed(): void
    {
        $inputs = [
            ['messages' => 'not-an-array'],
            ['messages' => []],
            ['messages' => [['role' => 'user', 'content' => 'ok', 'tool_calls' => 'not-an-array']]],
            ['messages' => [['role' => 'user', 'content' => 'ok', 'tool_calls' => ['bad']]]],
            $this->request([79991234567 => 'number-as-key']),
            $this->request(array_fill(0, 100_001, true)),
            $this->request(str_repeat('x', 4_194_305)),
        ];
        foreach ($inputs as $input) {
            try {
                (new ContactBoundary)->assertSafe($input);
                self::fail('Uninspectable input was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_deep_percent_html_and_escape_chains_fail_closed(): void
    {
        $encoded = '%40';
        for ($i = 0; $i < 10; $i++) {
            $encoded = rawurlencode($encoded);
        }
        foreach (['user'.$encoded.'example.test', 'user&amp;amp;#64;example.test', 'user\\x40example.test'] as $input) {
            try {
                (new ContactBoundary)->assertSafe($this->request($input));
                self::fail('Nested contact encoding was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($input, (string) $e);
            }
        }
    }

    public function test_full_exception_trace_contains_no_contacts_when_php_includes_arguments(): void
    {
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', __DIR__.'/Fixtures/trace-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(0, $status, $stderr);
        self::assertSame("clean\n", $stdout);
    }
}
