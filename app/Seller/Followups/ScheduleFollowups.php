<?php

namespace App\Seller\Followups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ScheduleFollowups extends Command
{
    protected $signature = 'seller:prepare-followups';

    protected $description = 'Подготовить напоминания по брендам';

    public function handle(): int
    {
        DB::table('brands')->where('status', 'active')->select(['id', 'organization_id'])->orderBy('id')->each(function ($brand): void {
            PrepareFollowups::dispatch($brand->organization_id, $brand->id);
        });

        return self::SUCCESS;
    }
}
