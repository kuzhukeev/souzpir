<?php

namespace App\Shell\Audit;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AuditLog extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    public function tenantUsesBrand(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Audit records are append-only.'));
        self::deleting(fn () => throw new LogicException('Audit records are append-only.'));
    }
}
