<?php

namespace App\Shell\Audit;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AuditObserver
{
    public function saving(Model $model): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Audited model mutations require a transaction.');
        }
    }

    public function deleting(Model $model): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Audited model mutations require a transaction.');
        }
    }

    public function created(Model $model): void
    {
        $this->record($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $keys = array_keys($model->getChanges());
        $before = array_intersect_key($model->getRawOriginal(), array_flip($keys));
        $this->record($model, 'updated', $before, $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $model->getOriginal(), null);
    }

    private function record(Model $model, string $action, ?array $before, ?array $after): void
    {
        app(AuditWriter::class)->record(app(TenantContext::class), new AuditEntry($action, $model::class, (string) $model->getKey(), $before, $after));
    }
}
