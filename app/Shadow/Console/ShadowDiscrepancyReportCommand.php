<?php

namespace App\Shadow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ShadowDiscrepancyReportCommand extends Command
{
    protected $signature = 'shadow:report {brand}';

    protected $description = 'Вывести отчёт расхождений теневого запуска для бренда';

    public function handle(): int
    {
        $brand = DB::table('brands')->where('id', $this->argument('brand'))->where('status', 'active')->first();
        if (! $brand) {
            $this->error('Активный бренд не найден.');

            return self::FAILURE;
        }
        $comparisons = DB::table('shadow_comparisons')
            ->where('organization_id', $brand->organization_id)->where('brand_id', $brand->id)
            ->orderBy('created_at')->get();
        $mismatches = $comparisons->where('verdict', 'mismatch');
        $this->line('Всего сравнений: '.$comparisons->count());
        $this->line('Расхождений: '.$mismatches->count());
        foreach ($mismatches as $row) {
            $this->line('- ['.$row->channel.'] '.$row->inbound_text);
            $this->line('  прототип: '.$row->baseline_response);
            $this->line('  новый движок: '.$row->candidate_response);
        }

        return self::SUCCESS;
    }
}
