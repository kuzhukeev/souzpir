<?php

namespace App\Shell\Audit;

trait AuditableModel
{
    public static function bootAuditableModel(): void
    {
        static::saving(fn ($model) => app(AuditObserver::class)->saving($model));
        static::deleting(fn ($model) => app(AuditObserver::class)->deleting($model));
        static::created(fn ($model) => app(AuditObserver::class)->created($model));
        static::updated(fn ($model) => app(AuditObserver::class)->updated($model));
        static::deleted(fn ($model) => app(AuditObserver::class)->deleted($model));
    }
}
