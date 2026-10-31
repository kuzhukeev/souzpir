<?php

namespace App\Ai\Drivers;

use App\Ai\CanonicalJson;

final class FakeDriver implements Driver
{
    public function complete(#[\SensitiveParameter] array $body): array
    {
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
}
