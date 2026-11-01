<?php

namespace App\Migration\Console;

use App\Migration\RollbackOrganizationBatch;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

final class RollbackOrganizationCopy extends Command
{
    protected $signature = 'migration:rollback-organization-copy {batch : ULID batch}';

    protected $description = 'Откатить неизменённый batch в изолированной тестовой базе';

    public function handle(RollbackOrganizationBatch $rollback): int
    {
        try {
            $rollback->rollback($this->argument('batch'));
            $this->line('Batch откачен.');

            return self::SUCCESS;
        } catch (LogicException $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Не удалось безопасно откатить batch.');
        }

        return self::FAILURE;
    }
}
