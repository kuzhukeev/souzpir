<?php

namespace App\Migration\Console;

use App\Migration\ImportPreparedOrganization;
use Illuminate\Console\Command;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ImportOrganizationCopy extends Command
{
    protected $signature = 'migration:import-organization-copy {source : Путь к offline SQLite-копии} {organization : ULID организации} {owner : ULID владельца в тестовой базе}';

    protected $description = 'Импортировать подготовленную организацию в изолированную тестовую базу';

    public function handle(ImportPreparedOrganization $importer): int
    {
        try {
            $result = $importer->import($this->argument('source'), $this->argument('organization'), $this->argument('owner'));
            $this->line('Batch: '.$result['batch'].'; брендов: '.$result['brands'].'; '.($result['created'] ? 'создан' : 'уже импортирован').'.');

            return self::SUCCESS;
        } catch (InvalidArgumentException|LogicException $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Не удалось импортировать копию.');
        }

        return self::FAILURE;
    }
}
