<?php

namespace App\Migration\Console;

use App\Migration\PreparedOrganization;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ValidatePreparedOrganization extends Command
{
    protected $signature = 'migration:validate-organization-copy {source : Путь к offline SQLite-копии} {organization : ULID организации}';

    protected $description = 'Проверить поля организации и брендов в подготовленной копии';

    public function handle(PreparedOrganization $prepared): int
    {
        try {
            $data = $prepared->read($this->argument('source'), $this->argument('organization'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('Организаций: 1; брендов: '.count($data['brands']).'.');

        return self::SUCCESS;
    }
}
