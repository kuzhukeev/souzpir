<?php

namespace App\Vision\Measurements;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class MeasurementSchedule
{
    public function save(TenantContext $context, array $data): void
    {
        $brand = DB::table('brands')->where('organization_id', $context->requireOrganizationId())
            ->where('id', $context->brandId)->first(['timezone']);
        if (! $brand) {
            throw new InvalidArgumentException('Brand unavailable.');
        }
        $enabled = (bool) $data['enabled'];
        $frequency = $data['frequency'] ?? null;
        $weekday = $data['weekday'] ?? null;
        $time = $data['local_time'] ?? null;
        $selected = $data['models'] ?? null;
        $models = null;
        if ($enabled) {
            if (! in_array($frequency, ['weekly', 'biweekly', 'monthly'], true) || ! is_int($weekday)
                || $weekday < 1 || $weekday > 7 || ! is_string($time) || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)
                || ! is_array($selected) || ! array_is_list($selected) || $selected === [] || count($selected) !== count(array_unique($selected))) {
                throw new InvalidArgumentException('Invalid schedule.');
            }
            $approval = DB::table('vision_question_approvals')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('active', true)->first(['providers']);
            $available = $approval ? json_decode($approval->providers, true, 32, JSON_THROW_ON_ERROR) : [];
            if (! is_array($available)) {
                throw new InvalidArgumentException('No approved models.');
            }
            $models = array_intersect($available, $selected);
            if (count($models) !== count($selected)) {
                throw new InvalidArgumentException('Model not approved.');
            }
        }
        $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
        $values = ['enabled' => $enabled, 'frequency' => $enabled ? $frequency : null,
            'weekday' => $enabled ? $weekday : null, 'local_time' => $enabled ? $time : null,
            'timezone' => $brand->timezone, 'models' => $models ? json_encode($models, JSON_THROW_ON_ERROR) : null,
            'next_run_at' => $enabled ? $this->nextAt($frequency, $weekday, $time, $brand->timezone)->utc() : null,
            'updated_at' => now()];
        $existing = DB::table('monitoring_schedules')->where($where)->first(['id']);
        $id = $existing?->id ?? (string) Str::ulid();
        if ($existing) {
            DB::table('monitoring_schedules')->where($where)->update($values);
        } else {
            DB::table('monitoring_schedules')->insert(['id' => $id, ...$where, ...$values, 'created_at' => now()]);
        }
        app(AuditWriter::class)->record($context, new AuditEntry('vision.schedule.saved', 'monitoring_schedule', $id,
            after: ['enabled' => $enabled, 'frequency' => $enabled ? $frequency : null]));
    }

    public function read(TenantContext $context): array
    {
        $brand = DB::table('brands')->where('organization_id', $context->organizationId)->where('id', $context->brandId)->first(['timezone']);
        $approval = DB::table('vision_question_approvals')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('active', true)->first(['providers']);
        $row = DB::table('monitoring_schedules')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->first();

        return ['enabled' => (bool) ($row?->enabled ?? false), 'frequency' => $row?->frequency,
            'weekday' => $row?->weekday, 'local_time' => $row?->local_time ? substr($row->local_time, 0, 5) : null,
            'timezone' => $brand?->timezone ?? 'Europe/Moscow', 'next_run_at' => $row?->next_run_at,
            'models' => $row?->models ? array_values(json_decode($row->models, true, 32, JSON_THROW_ON_ERROR)) : [],
            'available_models' => $approval ? array_values(json_decode($approval->providers, true, 32, JSON_THROW_ON_ERROR)) : []];
    }

    public function dispatchDue(): void
    {
        if (! config('eyon.measurement_schedule_enabled', false) || ! config('eyon.measurement_queue_enabled', false)) {
            return;
        }
        $due = DB::table('monitoring_schedules')->where('enabled', true)->where('next_run_at', '<=', now())->limit(100)->get();
        foreach ($due as $item) {
            try {
                app(TenantContextRunner::class)->run(new TenantContext($item->organization_id, $item->brand_id, source: 'job'), function () use ($item): void {
                    DB::transaction(function () use ($item): void {
                        $where = ['organization_id' => $item->organization_id, 'brand_id' => $item->brand_id];
                        $schedule = DB::table('monitoring_schedules')->where($where)->where('id', $item->id)->lockForUpdate()->first();
                        if (! $schedule || ! $schedule->enabled || CarbonImmutable::parse($schedule->next_run_at)->isFuture()) {
                            return;
                        }
                        $approval = DB::table('vision_question_approvals')->where($where)->where('active', true)->first(['question_set_id', 'providers']);
                        $models = json_decode($schedule->models, true, 32, JSON_THROW_ON_ERROR);
                        $available = $approval ? json_decode($approval->providers, true, 32, JSON_THROW_ON_ERROR) : [];
                        if (! $approval || ! is_array($models) || $models === [] || array_intersect_assoc($models, $available) !== $models) {
                            DB::table('monitoring_schedules')->where($where)->where('id', $item->id)->update(['enabled' => false, 'next_run_at' => null, 'updated_at' => now()]);

                            return;
                        }
                        $measurement = app(MeasurementRunner::class)->start($approval->question_set_id, $models, 1);
                        app(AuditWriter::class)->record(app(TenantContext::class), new AuditEntry('vision.measurement.scheduled', 'measurement', $measurement, after: ['schedule_id' => $schedule->id]));
                        $next = $this->advance($schedule);
                        DB::table('monitoring_schedules')->where($where)->where('id', $item->id)->update(['next_run_at' => $next->utc(), 'updated_at' => now()]);
                        foreach (DB::table('measurement_answers')->where($where)->where('measurement_id', $measurement)->pluck('id') as $answer) {
                            RunMeasurement::dispatch($item->organization_id, $item->brand_id, $measurement, $answer)->afterCommit();
                        }
                    });
                });
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    private function nextAt(string $frequency, int $weekday, string $time, string $timezone): CarbonImmutable
    {
        $now = CarbonImmutable::now($timezone);
        $candidate = $frequency === 'monthly' ? $now->startOfMonth() : $now->startOfDay();
        $candidate = $candidate->setTimeFromTimeString($time);
        while ($candidate->dayOfWeekIso !== $weekday) {
            $candidate = $candidate->addDay();
        }
        if ($frequency === 'monthly' && $candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($time);
            while ($candidate->dayOfWeekIso !== $weekday) {
                $candidate = $candidate->addDay();
            }
        } elseif ($frequency !== 'monthly' && $candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addWeek();
        }

        return $candidate;
    }

    private function advance(object $schedule): CarbonImmutable
    {
        $current = CarbonImmutable::parse($schedule->next_run_at)->setTimezone($schedule->timezone);
        if ($schedule->frequency === 'monthly') {
            $next = $current->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString(substr($schedule->local_time, 0, 5));
            while ($next->dayOfWeekIso !== (int) $schedule->weekday) {
                $next = $next->addDay();
            }

            return $next;
        }

        return $current->addWeeks($schedule->frequency === 'biweekly' ? 2 : 1);
    }
}
