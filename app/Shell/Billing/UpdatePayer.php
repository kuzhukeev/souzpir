<?php

namespace App\Shell\Billing;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class UpdatePayer
{
    public function __construct(private BillingAccess $access, private AuditWriter $audit) {}

    public function run(#[SensitiveParameter] Request $request, #[SensitiveParameter] array $data): void
    {
        try {
            app(AuthSessionRegistry::class)->withinActive($request, function () use ($data): void {
                $org = $this->access->lock(true);
                $settings = $org->settings ?? [];
                $payer = $settings['payer'] ?? [];
                if (! is_array($payer)) {
                    $payer = [];
                }
                $changed = [];
                foreach (['legal_name', 'inn', 'kpp', 'address', 'email'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        continue;
                    }
                    $value = $data[$field] === null ? '' : trim($data[$field]);
                    if (($payer[$field] ?? '') !== $value) {
                        $changed[] = $field;
                    }
                    $payer[$field] = $value;
                }
                if ($changed === []) {
                    return;
                }
                $settings['payer'] = $payer;
                $org->forceFill(['settings' => $settings])->save();
                $this->audit->record(app(TenantContext::class), new AuditEntry('billing.payer.updated', 'organization', $org->id,
                    after: ['changed_fields' => $changed]));
            });
        } catch (Throwable $error) {
            // SQL exceptions and their bindings can contain the whole payer JSON.
            if ($error instanceof AuthenticationException) {
                throw new AuthenticationException;
            }
            if ($error instanceof AuthorizationException) {
                throw (new AuthorizationException)->withStatus($error->status());
            }
            if ($error instanceof ModelNotFoundException) {
                throw new ModelNotFoundException;
            }
            if ($error instanceof HttpExceptionInterface) {
                throw new HttpException($error->getStatusCode(), '', null, $error->getHeaders());
            }
            throw new \RuntimeException(__('Shell/Billing/messages.payer.failed'));
        }
    }
}
