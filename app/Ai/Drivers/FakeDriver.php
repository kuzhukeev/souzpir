<?php

namespace App\Ai\Drivers;

use App\Ai\CanonicalJson;
use App\Ai\SchemaObjects;
use Throwable;

final class FakeDriver implements Driver
{
    public function complete(#[\SensitiveParameter] array $body): array
    {
        SchemaObjects::forInspection($body);
        $hash = CanonicalJson::hash($body);
        $directory = config('features.E0-05.defaults.fixture_directory', base_path('tests/fixtures/llm'));
        $file = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$hash.'.json';
        if (! is_file($file)) {
            throw new Rejected("AI fixture missing for normalized request {$hash}; record or supply a reviewed fixture.");
        }
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! isset($data['result']) || ! is_array($data['result'])) {
            throw new Rejected('Invalid AI fixture.');
        }

        return $data['result'];
    }

    /** Dedicated fixture format carries the original arguments as JSON strings. */
    public function completeWithRawToolCalls(#[\SensitiveParameter] array $body): array
    {
        try {
            SchemaObjects::forInspection($body);
            $hash = CanonicalJson::hash($body);
            $directory = config('features.E0-05.defaults.fixture_directory', base_path('tests/fixtures/llm'));
            $file = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$hash.'.json';
            if (! is_file($file)) {
                throw new Rejected('Missing fixture.');
            }
            $json = file_get_contents($file);
            if (! is_string($json)) {
                throw new Rejected('Unreadable fixture.');
            }
            $parser = app(ProtectedRawToolCalls::class);
            $data = $parser->decodeEnvelope($json);
            if (! is_array($data['result'] ?? null) || ! is_array($data['result']['toolCalls'] ?? null)) {
                throw new Rejected('Invalid fixture.');
            }
            $calls = $parser->parse($data['result']['toolCalls'], false);
            $result = $data['result'];
            $result['toolCalls'] = $calls['normalized'];

            return ['result' => $result, 'raw_tool_calls' => $calls['raw']];
        } catch (Throwable) {
            throw new Rejected('Protected AI fixture unavailable.');
        }
    }
}
