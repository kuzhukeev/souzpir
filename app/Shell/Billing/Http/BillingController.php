<?php

namespace App\Shell\Billing\Http;

use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Billing\BillingAccess;
use App\Shell\Billing\BillingPeriod;
use App\Shell\Billing\BillingQuery;
use App\Shell\Billing\PayerSnapshot;
use App\Shell\Billing\SubmissionScope;
use App\Shell\Billing\UpdatePayer;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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

            return $query->read($tenant, $tab, $from, $to, $owner);
        });

        return Inertia::render('Shell/Billing/Index', $props);
    }

    public function payerData(#[SensitiveParameter] Request $request): JsonResponse
    {
        try {
            $payer = app(AuthSessionRegistry::class)->withinActive($request,
                fn () => PayerSnapshot::read($this->access->lockPayer($request)));

            return response()->json($payer)->header('Cache-Control', 'private, no-store');
        } catch (AuthenticationException) {
            throw new AuthenticationException;
        } catch (AuthorizationException $error) {
            throw (new AuthorizationException)->withStatus($error->status());
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        } catch (HttpExceptionInterface $error) {
            throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
        } catch (Throwable) {
            // Exception details or JSON encoding failures can contain sensitive settings.
            throw new \RuntimeException(__('Shell/Billing/messages.payer.load_failed'));
        }
    }

    public function updatePayer(#[SensitiveParameter] Request $request, UpdatePayer $action): RedirectResponse|JsonResponse
    {
        // Check authorization before validation and again under the same lock as the write.
        try {
            app(AuthSessionRegistry::class)->withinActive($request, fn () => $this->access->lockPayer($request));
        } catch (AuthenticationException) {
            throw new AuthenticationException;
        } catch (AuthorizationException $error) {
            throw (new AuthorizationException)->withStatus($error->status());
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        } catch (HttpExceptionInterface $error) {
            throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
        } catch (Throwable) {
            throw new \RuntimeException(__('Shell/Billing/messages.payer.failed'));
        }
        $revision = $request->header('X-Payer-Revision');
        abort_unless(is_string($revision) && preg_match('/^[a-f0-9]{64}$/D', $revision) === 1, 409);
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
            if ($request->expectsJson()) {
                return response()->json(['errors' => $validator->errors()], 422)->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('billing.payer')->withErrors($validator);
        }
        $action->run($request, $validator->validated());

        if ($request->expectsJson()) {
            return response()->json(['data' => ['saved' => true]])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('billing.payer');
    }

    public function requestPlan(#[SensitiveParameter] Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($tenant, $request): void {
            $this->access->lock(true);
            $scope = SubmissionScope::for($tenant);
            $expected = $request->header('X-Billing-Context');
            abort_unless(app(TenantContext::class) === $tenant && $scope !== null && is_string($expected) && hash_equals($scope, $expected), 409);
        });
        $applicationKey = config('app.key');
        abort_unless(app()->bound(PlanRequestPort::class) && is_string($applicationKey) && $applicationKey !== '', 503, __('Shell/Billing/messages.plan_request_unavailable'));
        $data = $request->validate(['plan_id' => ['required', 'ulid'], 'submission_key' => ['required', 'ulid']]);
        // The port owns idempotency, tenant checks, audit and notification as one transaction;
        // it must be called outside any surrounding transaction.
        try {
            abort_unless(app(TenantContext::class) === $tenant, 409);
            $result = app(PlanRequestPort::class)->submit($request, new PlanRequestCommand($data['plan_id'], $data['submission_key']));
        } catch (AuthenticationException) {
            throw new AuthenticationException;
        } catch (AuthorizationException $error) {
            throw (new AuthorizationException)->withStatus($error->status());
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException;
        } catch (HttpExceptionInterface $error) {
            throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
        } catch (Throwable) {
            // Unexpected database/transport errors may contain ticket data and bindings.
            throw new \RuntimeException(__('Shell/Billing/messages.plan_request_failed'));
        }

        return response()->json(['data' => ['ticket_id' => $result->ticketId, 'created' => $result->created,
            'delivery_status' => $result->deliveryStatus->value, 'next_submission_key' => (string) Str::ulid()]], $result->created ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }
}
