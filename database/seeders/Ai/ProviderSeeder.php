<?php

namespace Database\Seeders\Ai;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit local/test import only: never overwrite an operator's provider status. */
final class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $names = ['chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'claude' => 'Claude', 'perplexity' => 'Perplexity', 'gigachat' => 'GigaChat', 'yandexgpt' => 'YandexGPT', 'alice' => 'Алиса AI', 'neuro' => 'Яндекс Нейро'];
        foreach (config('features.E0-05.defaults.models', []) as $model) {
            $code = $model['provider'];
            DB::table('ai_providers')->insertOrIgnore(['code' => $code, 'caption' => $names[$code] ?? $code, 'status' => $model['status'], 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
