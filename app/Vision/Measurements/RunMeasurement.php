<?php

namespace App\Vision\Measurements;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class RunMeasurement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $organizationId, public string $brandId, public string $measurementId) {}

    public function handle(TenantContextRunner $runner): void
    {
        $runner->run(new TenantContext($this->organizationId, $this->brandId, source: 'job'), function (): void {
            $answers = DB::table('measurement_answers')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
                ->where('measurement_id', $this->measurementId)->where('status', 'pending')->orderBy('question_n')->orderBy('provider')->orderBy('run_no')->pluck('id');
            foreach ($answers as $id) {
                try {
                    app(MeasurementRunner::class)->measure($id);
                } catch (\LogicException) {
                    // The runner records the uncertain or failed slot; continue with remaining slots.
                }
            }
        });
    }
}
