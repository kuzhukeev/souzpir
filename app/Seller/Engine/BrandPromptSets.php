<?php

namespace App\Seller\Engine;

use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Keep the imported prototype on v1; other brands use their own trusted v2 rows. */
final class BrandPromptSets
{
    public const NAMES = ['extractor', 'guard', 'summary', 'simulator', 'judge'];

    public function load(TenantContext $tenant, KnowledgeSnapshot $knowledge, string $name): object
    {
        $this->assertTenant($tenant, $name);
        $persona = $knowledge->persona();
        $legacy = ($persona['company'] ?? null) === 'Союз Пир'
            && str_contains(mb_strtolower((string) ($persona['intro'] ?? '')), 'пиротехник');
        $version = $legacy ? 'v1' : 'v2';
        if (! $legacy) {
            $this->seed($tenant, $name);
        }
        $row = DB::table('prompt_sets')->where('name', $name)->where('version', $version)->where('status', 'published')
            ->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        if ($legacy) {
            $row ??= DB::table('prompt_sets')->where('name', $name)->where('version', 'v1')->where('status', 'published')
                ->whereNull('organization_id')->whereNull('brand_id')->first();
        }
        $path = resource_path('views/prompts/'.($legacy ? '' : 'v2/').$name.'.blade.php');
        $trusted = file_get_contents($path);
        if (! $row || ! is_string($trusted) || ! hash_equals(hash('sha256', $trusted), $row->checksum)
            || $row->body !== $trusted) {
            throw new LogicException('Published brand prompt is unavailable.');
        }

        return $row;
    }

    public function seed(TenantContext $tenant, string $name): void
    {
        $this->assertTenant($tenant, $name);
        $body = file_get_contents(resource_path('views/prompts/v2/'.$name.'.blade.php'));
        if (! is_string($body)) {
            throw new LogicException('Brand prompt source is missing.');
        }
        DB::table('prompt_sets')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
            'name' => $name, 'version' => 'v2', 'body' => $body, 'status' => 'published',
            'checksum' => hash('sha256', $body), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertTenant(TenantContext $tenant, string $name): void
    {
        if (! $tenant->organizationId || ! $tenant->brandId || ! in_array($name, self::NAMES, true)) {
            throw new LogicException('Brand prompt requires a tenant and known stage.');
        }
    }
}
