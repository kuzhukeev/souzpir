<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportSellerKnowledge extends Command
{
    protected $signature = 'seller:knowledge-import {brand} {dir}';

    protected $description = 'Импортирует и публикует десять YAML базы знаний бренда';

    public function handle(YamlKnowledgeImporter $importer): int
    {
        $brand = DB::table('brands')->where('id', $this->argument('brand'))->where('status', 'active')->first();
        if (! $brand) {
            $this->error('Активный бренд не найден.');

            return self::FAILURE;
        }
        $organization = DB::table('organizations')->where('id', $brand->organization_id)->where('status', 'active')->first();
        $membership = $organization ? Membership::withoutGlobalScopes()->where('organization_id', $organization->id)->where('user_id', $organization->owner_user_id)->where('status', 'active')->first() : null;
        if (! $membership || ! $membership->permitsBrand($brand->id)) {
            $this->error('Владелец организации не имеет доступа к бренду.');

            return self::FAILURE;
        }
        $tenant = new TenantContext($organization->id, $brand->id, $membership->user_id, $membership->id, source: 'job');
        try {
            $snapshot = app(TenantContextRunner::class)->run($tenant, fn () => $importer->importAndPublish($tenant, $this->argument('dir'), true));
        } catch (Throwable $error) {
            $this->error('Импорт не выполнен: '.$error->getMessage());

            return self::FAILURE;
        }
        foreach (YamlKnowledgeImporter::FILES as $file) {
            $section = $snapshot->all($file);
            $count = match ($file) {
                'facts' => array_sum(array_map(fn ($group) => count($group['facts'] ?? []), $section)),
                'fewshot' => count($section['examples'] ?? []),
                'escalation' => count($section['reasons'] ?? []),
                default => count($section),
            };
            $this->line($file.': '.$count);
        }
        $this->info('Опубликован релиз '.$snapshot->releaseId().'.');

        return self::SUCCESS;
    }
}
