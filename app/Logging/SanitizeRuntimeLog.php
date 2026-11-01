<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class SanitizeRuntimeLog
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            message: 'Фоновый процесс: событие без подробностей.',
            context: [],
            extra: [],
        ));
    }
}
