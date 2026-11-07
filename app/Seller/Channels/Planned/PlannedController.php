<?php

namespace App\Seller\Channels\Planned;

use App\Models\Membership;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PlannedController
{
    private const TABLE = 'seller_planned_integration_interests';

    private const REMINDER_REASON = 'Напоминания готовятся в тестовом режиме. Внешняя отправка писем отключена.';

    private const REMINDERS_TABLE = 'seller_contact_reminder_settings';

    public function vk(Request $request, TenantContext $context): Response
    {
        return $this->page($request, $context, 'vk');
    }

    public function whatsapp(Request $request, TenantContext $context): Response
    {
        return $this->page($request, $context, 'whatsapp');
    }

    public function calendar(Request $request, TenantContext $context): Response
    {
        return $this->page($request, $context, 'calendar');
    }

    public function index(Request $request, TenantContext $context): Response
    {
        return $this->page($request, $context, 'planned');
    }

    private function page(Request $request, TenantContext $context, string $section): Response
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $section): Response {
            $this->assertAccess($context, true, false);
            Gate::authorize('seller.settings.read');
            $available = Schema::hasTable(self::TABLE);
            $remindersAvailable = Schema::hasTable(self::REMINDERS_TABLE) && config('runtime.notification_driver') === 'fake';
            $remindersEnabled = $remindersAvailable && (bool) DB::table(self::REMINDERS_TABLE)->where([
                'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
            ])->value('enabled');
            $interested = $available ? DB::table(self::TABLE)
                ->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)
                ->where('user_id', $context->actorId)->pluck('integration')->all() : [];
            $items = [];
            foreach (PlannedCatalog::ITEMS as $key => [$label, $description]) {
                $items[] = ['key' => $key, 'label' => $label, 'description' => $description, 'interested' => in_array($key, $interested, true)];
            }

            return Inertia::render('Seller/Channels/Planned/Index', [
                'section' => $section,
                'planned' => [
                    'items' => $items,
                    'reminders' => ['enabled' => $remindersEnabled, 'available' => $remindersAvailable,
                        'reason' => $remindersAvailable ? self::REMINDER_REASON : 'Напоминания пока недоступны: тестовая доставка не включена.'],
                    'canManage' => Gate::allows('seller.settings.write'),
                    'interestAvailable' => $available,
                    'brandId' => $context->brandId,
                ],
            ]);
        });
    }

    public function interest(Request $request, TenantContext $context): JsonResponse
    {
        Gate::authorize('seller.settings.write');
        $this->assertExpectedBrand($request, $context);
        $data = $request->validate(['integration' => ['required', 'string', Rule::in(array_keys(PlannedCatalog::ITEMS))]]);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $data): void {
            $this->assertAccess($context, true, true);
            abort_unless(Schema::hasTable(self::TABLE), 503, 'Сохранение заявки пока недоступно.');
            $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'user_id' => $context->actorId, 'integration' => $data['integration']];
            // Brand lock serializes repeated requests and audit recording with other tenant writes.
            if (! DB::table(self::TABLE)->where($where)->exists()) {
                $id = (string) Str::ulid();
                DB::table(self::TABLE)->insert(['id' => $id, ...$where, 'created_at' => now(), 'updated_at' => now()]);
                app(AuditWriter::class)->record($context, new AuditEntry('seller.planned_interest.created', 'seller_planned_interest', $id,
                    after: ['integration' => $data['integration']]));
            }
        });

        return response()->json(['data' => ['integration' => $data['integration'], 'interested' => true]]);
    }

    public function reminders(Request $request, TenantContext $context): JsonResponse
    {
        Gate::authorize('seller.settings.write');
        $this->assertExpectedBrand($request, $context);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $data): void {
            $this->assertAccess($context, true, true);
            abort_unless(Schema::hasTable(self::REMINDERS_TABLE) && config('runtime.notification_driver') === 'fake', 503, self::REMINDER_REASON);
            $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId];
            $row = DB::table(self::REMINDERS_TABLE)->where($where)->first();
            if ($row) {
                DB::table(self::REMINDERS_TABLE)->where('id', $row->id)->update(['enabled' => $data['enabled'], 'updated_at' => now()]);
            } else {
                DB::table(self::REMINDERS_TABLE)->insert(['id' => (string) Str::ulid(), ...$where,
                    'enabled' => $data['enabled'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['data' => ['enabled' => (bool) $data['enabled'], 'available' => true, 'reason' => self::REMINDER_REASON]]);
    }

    private function assertExpectedBrand(Request $request, TenantContext $context): void
    {
        abort_unless($context->brandId !== null && $request->header('X-Brand-Id') === $context->brandId, 404);
    }

    private function assertAccess(TenantContext $context, bool $lock, bool $write): void
    {
        abort_unless($context->organizationId && $context->brandId && $context->membershipId && $context->actorId, 403);
        if ($lock) {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
        }
        $query = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $context->organizationId)
            ->where('user_id', $context->actorId)->where('status', 'active');
        $member = ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(403);
        abort_unless($member->permitsBrand($context->brandId) && (! $write || in_array($member->role, ['owner', 'admin'], true)), 403);
        abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
    }
}
