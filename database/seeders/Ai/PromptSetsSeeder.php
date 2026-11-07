<?php

namespace Database\Seeders\Ai;

use App\Seller\Engine\BrandPromptSets;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Explicit platform seed: exact source bytes and checksum, no silent overwrites. */
final class PromptSetsSeeder extends Seeder
{
    public function seedBrand(TenantContext $tenant): void
    {
        foreach (BrandPromptSets::NAMES as $name) {
            app(BrandPromptSets::class)->seed($tenant, $name);
        }
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['extractor', 'guard', 'judge', 'responder', 'simulator', 'summary'] as $name) {
                $path = resource_path("views/prompts/{$name}.blade.php");
                $body = file_get_contents($path);
                if ($body === false) {
                    throw new LogicException('Prompt template is missing.');
                }
                $checksum = hash('sha256', $body);
                DB::table('prompt_sets')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => null, 'brand_id' => null, 'name' => $name, 'version' => 'v1', 'body' => $body, 'status' => 'published', 'checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
                $existing = DB::table('prompt_sets')->whereNull('organization_id')->whereNull('brand_id')->where('name', $name)->where('version', 'v1')->first();
                if (! $existing || ! hash_equals($existing->checksum, $checksum) || $existing->body !== $body) {
                    throw new LogicException('Existing v1 prompt differs from the source; use a new version.');
                }
            }
        });
    }
}
