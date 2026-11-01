<?php

namespace App\Migration\Console;

use App\Migration\OrganizationDryRun;
use Illuminate\Console\Command;
use Throwable;

final class InspectOrganization extends Command
{
    protected $signature = 'migration:inspect-organization {source : Путь к offline SQLite-копии источника} {target : Путь к offline SQLite-копии цели} {organization : ULID организации} {owner : ULID владельца в целевой копии}';

    protected $description = 'Проверить сопоставление организации и брендов без записи';

    public function handle(OrganizationDryRun $inspection): int
    {
        try {
            $report = $inspection->inspect($this->argument('source'), $this->argument('target'), $this->argument('organization'), $this->argument('owner'));
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report['errors'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Не удалось проверить копии: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
