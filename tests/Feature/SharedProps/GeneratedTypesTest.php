<?php

namespace Tests\Feature\SharedProps;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GeneratedTypesTest extends TestCase
{
    public function test_committed_generated_types_match_sources(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('scripts/generate-types/generate.php'), '--check'], base_path());
        $process->setTimeout(90)->mustRun();
        $this->assertStringContainsString('Generated frontend contracts match', $process->getOutput());
    }

    public function test_generation_check_rejects_drift(): void
    {
        $directory = base_path('.local/runtime/testing/drift-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0700, true);
        try {
            foreach (['data.ts', 'permissions.ts', 'translations.ts', 'routes.d.ts'] as $file) {
                copy(base_path('resources/js/types/generated/'.$file), $directory.'/'.$file);
            }
            file_put_contents($directory.'/data.ts', "// stale\n");
            $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('scripts/generate-types/generate.php'), '--check', '--output-dir='.$directory], base_path());
            $process->setTimeout(90)->run();
            $this->assertFalse($process->isSuccessful(), 'Type drift must fail CI');
            $this->assertStringContainsString('stale: data.ts', $process->getErrorOutput().$process->getOutput());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
