<?php

namespace App\Seller\Channels\Email;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class EmailSettingsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    public function index(Request $request): mixed
    {
        [$settings, $delivery] = $this->access($request, fn (TenantContext $tenant) => [DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first(['sender_email', 'sender_name', 'enabled']), app(EmailDeliverySettings::class)->read($tenant)]);

        return Inertia::render('Seller/Channels/Email/Index', ['settings' => $settings, 'delivery' => $delivery, 'status' => session('status')]);
    }

    public function update(Request $request): mixed
    {
        $input = $request->validate(['sender_email' => ['required', 'email:rfc', 'max:254'], 'sender_name' => ['required', 'string', 'max:100'], 'enabled' => ['required', 'boolean'], 'mode' => ['sometimes', Rule::in(['eyon', 'smtp'])], 'signature' => ['nullable', 'string', 'max:5000'], 'smtp_host' => [Rule::requiredIf($request->input('mode') === 'smtp'), 'nullable', 'string', 'max:255'], 'smtp_port' => [Rule::requiredIf($request->input('mode') === 'smtp'), 'nullable', 'integer', 'between:1,65535'], 'smtp_username' => ['nullable', 'string', 'max:255'], 'smtp_password' => ['nullable', 'string', 'max:255'], 'smtp_security' => ['nullable', Rule::in(['tls', 'ssl'])]]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            DB::transaction(function () use ($tenant, $input): void {
                $query = DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
                $row = $query->first();
                $id = $row?->id ?? (string) Str::ulid();
                DB::table('seller_email_price_settings')->updateOrInsert(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
                    ['id' => $id, 'sender_email' => $input['sender_email'], 'sender_name' => $input['sender_name'], 'enabled' => $input['enabled'], 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
                if (isset($input['mode'])) {
                    app(EmailDeliverySettings::class)->save($tenant, ['mode' => $input['mode'], 'signature' => $input['signature'] ?? '', 'smtp_host' => $input['smtp_host'] ?? '', 'smtp_port' => $input['smtp_port'] ?? 587, 'smtp_username' => $input['smtp_username'] ?? '', 'smtp_security' => $input['smtp_security'] ?? 'tls'], $input['smtp_password'] ?? null);
                }
                app(AuditWriter::class)->record($tenant, new AuditEntry('seller.email_price.settings.updated', 'seller_email_price_settings', $id, after: ['enabled' => (bool) $input['enabled']]));
            });
        });

        return back()->with('status', 'Настройки отправителя сохранены.');
    }

    public function test(Request $request): mixed
    {
        $input = $request->validate(['recipient' => ['required', 'email:rfc', 'max:254']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $settings = DB::table('seller_email_price_settings')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
            if (! $settings) {
                throw ValidationException::withMessages(['recipient' => 'Сначала сохраните настройки отправителя.']);
            }
            $signature = app(EmailDeliverySettings::class)->read($tenant)['signature'];
            Mail::mailer('array')->raw("Тестовое письмо\n\n".$signature, fn ($message) => $message->from($settings->sender_email, $settings->sender_name)->to($input['recipient'])->subject('Тестовое письмо'));
        });

        return back()->with('status', 'Тест выполнен в безопасном режиме: письмо не отправлено.');
    }
}
