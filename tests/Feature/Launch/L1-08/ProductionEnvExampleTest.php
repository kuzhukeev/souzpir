<?php

namespace Tests\Feature\Launch\L1_08;

use Tests\TestCase;

final class ProductionEnvExampleTest extends TestCase
{
    public function test_every_config_env_key_is_present_in_env_production_example(): void
    {
        $keys = [];
        foreach (glob(base_path('config/*.php')) as $file) {
            preg_match_all('/env\(\s*([\'"])([A-Z0-9_]+)\1/', file_get_contents($file), $matches);
            foreach ($matches[2] as $key) {
                $keys[$key] = true;
            }
        }
        self::assertNotEmpty($keys);

        $example = file_get_contents(base_path('.env.production.example'));
        $missing = [];
        foreach (array_keys($keys) as $key) {
            if (! preg_match('/^'.preg_quote($key, '/').'=/m', $example)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'Missing keys in .env.production.example: '.implode(', ', $missing));
    }

    public function test_production_example_disables_app_debug_and_live_channels(): void
    {
        $example = file_get_contents(base_path('.env.production.example'));

        self::assertMatchesRegularExpression('/^APP_ENV=production$/m', $example);
        self::assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
        self::assertMatchesRegularExpression('/^AI_DRIVER=fake$/m', $example);
        self::assertMatchesRegularExpression('/^DELIVERY_DRIVER=fake$/m', $example);
        self::assertMatchesRegularExpression('/^NOTIFICATION_DRIVER=fake$/m', $example);
        self::assertMatchesRegularExpression('/^VISION_LIVE_MEASUREMENTS_ENABLED=false$/m', $example);
        self::assertDoesNotMatchRegularExpression('/^DB_PASSWORD=\S/m', $example);
        self::assertDoesNotMatchRegularExpression('/^AWS_SECRET_ACCESS_KEY=\S/m', $example);
    }
}
