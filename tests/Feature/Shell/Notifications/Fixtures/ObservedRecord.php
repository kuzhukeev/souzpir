<?php

namespace Tests\Feature\Shell\Notifications\Fixtures;

use App\Shell\Audit\AuditableModel;
use Illuminate\Database\Eloquent\Model;

final class ObservedRecord extends Model
{
    use AuditableModel;

    protected $table = 'notification_observer_records';

    protected $guarded = [];

    public $timestamps = false;
}
