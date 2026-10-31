<?php

namespace App\Shell\Billing\Http;

use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Billing\BillingAccess;
use App\Shell\Billing\BillingPeriod;
use App\Shell\Billing\BillingQuery;
use App\Shell\Billing\UpdatePayer;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use SensitiveParameter;

final class BillingController
{
    public function __construct(private BillingAccess $access) {}

    public function index(Request $request, BillingQuery $query): Response
    {
        $tab = $request->route('tab');
        $manage = in_array($tab, ['plans', 'invoices', 'payer'], true);
        app(AuthSessionRegistry::class)->withinActive($request, fn () => $this->access->lock($manage));
        $input = Validator::make($request->query(), BillingPeriod::rules(), BillingPeriod::messages())->validate();
        $now = CarbonImmutable::now('UTC');
        $from = isset($input['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], 'UTC') : $now->startOfMonth();
        $to = isset($input['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $input['to'], 'UTC') : $now->endOfMonth()->startOfDay();
        $tenant = app(TenantContext::class);
        $props = app(AuthSessionRegistry::class)->withinActive($request, function () use ($tenant, $tab, $manage, $query, $from, $to): array {
            $org = $this->access->lock($manage);
            $owner = $org->owner_user_id === $tenant->actorId && Gate::allows('billing.manage');

            return $query->read($tenant, $org, $tab, $from, $to, $owner);
        });

        return Inertia::render('Shell/Billing/Index', $props);
    }

    public function updatePayer(#[SensitiveParameter] Request $request, UpdatePayer $action): RedirectResponse
    {
        // Check authorization before validation and again under the same lock as the write.
        app(AuthSessionRegistry::class)->withinActive($request, fn () => $this->access->lock(true));
        $validator = Validator::make($request->all(), [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inn' => ['sometimes', 'nullable', 'string', 'regex:/^(?:[0-9]{10}|[0-9]{12})$/D'],
            'kpp' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{9}$/D'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
        ], [
            'legal_name.string' => __('Shell/Billing/messages.payer.legal_name_string'), 'legal_name.max' => __('Shell/Billing/messages.payer.legal_name_max'),
            'inn.string' => __('Shell/Billing/messages.payer.inn_string'), 'inn.regex' => __('Shell/Billing/messages.payer.inn_regex'),
            'kpp.string' => __('Shell/Billing/messages.payer.kpp_string'), 'kpp.regex' => __('Shell/Billing/messages.payer.kpp_regex'),
            'address.string' => __('Shell/Billing/messages.payer.address_string'), 'address.max' => __('Shell/Billing/messages.payer.address_max'),
            'email.email' => __('Shell/Billing/messages.payer.email_email'), 'email.max' => __('Shell/Billing/messages.payer.email_max'),
        ]);
        if ($validator->fails()) {
            // Payer information is not flashed into the session on validation errors.
            return redirect()->route('billing.payer')->withErrors($validator);
        }
        $action->run($request, $validator->validated());

        return redirect()->route('billing.payer');
    }

    public function requestPlan(Request $request): never
    {
        app(AuthSessionRegistry::class)->withinActive($request, fn () => $this->access->lock(true));
        // The support plan-request port and its notification event are not installed yet.
        abort(503, __('Shell/Billing/messages.plan_request_unavailable'));
    }
}
