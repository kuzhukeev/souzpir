<?php

namespace App\Shell\Integrations\Keys;

use App\Models\Brand;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Brands\BrandAccess;
use App\Shell\Integrations\SecretEnvelope;
use App\Shell\Integrations\SecretSessionStore;
use App\Shell\Integrations\SecretTransport;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class KeysController
{
    private const ABILITIES = ['read', 'turn', 'leads'];

    public function __construct(private readonly BrandAccess $access) {}

    /** @return list<array<string, mixed>> */
    public static function listing(TenantContext $ctx): array
    {
        if (! $ctx->organizationId || ! $ctx->brandId) {
            return [];
        }

        return DB::table('personal_access_tokens')
            ->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)
            ->where('tokenable_type', Brand::class)->where('tokenable_id', $ctx->brandId)
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(static fn ($row): array => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'mask' => '••••'.$row->token_last_four,
                'abilities' => json_decode($row->abilities ?? '[]', true, flags: JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
                'last_used_at' => $row->last_used_at,
            ])->all();
    }

    public function store(Request $request): RedirectResponse
    {
        SecretTransport::assertAllowed($request);
        SecretSessionStore::assertReady();
        SecretSessionStore::assertIndependentCommit();
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId, 404);
        $this->access->read($request, $ctx->brandId, 'integrations.write');
        $pending = app(SecretEnvelope::class)->open($request, $ctx, 'integration_api_key', $request->session()->get('integration_api_key'));
        if ($pending !== null && ActiveApiKey::matches($ctx, $pending['value'])
            && DB::table('integration_secret_receipts')->where('nonce_hash', $pending['nonce_hash'])
                ->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['name' => 'Сначала покажите ранее созданный ключ или отзовите его.']);
        }
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1', 'max:3'],
            'abilities.*' => ['required', 'distinct', Rule::in(self::ABILITIES)],
        ])->validate();
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Укажите название ключа.']);
        }

        $sealed = $this->access->change($request, $ctx->brandId, function (Brand $brand, TenantContext $context) use ($data, $name, $request): string {
            $secret = Str::random(64);
            $id = DB::table('personal_access_tokens')->insertGetId([
                'tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
                'organization_id' => $context->organizationId, 'brand_id' => $brand->id,
                'name' => $name, 'token' => hash('sha256', $secret), 'token_last_four' => substr($secret, -4),
                'abilities' => json_encode(array_values($data['abilities']), JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditWriter::class)->record($context, new AuditEntry('integration.api_key.created', 'personal_access_token', (string) $id, after: ['name' => $name, 'abilities' => array_values($data['abilities'])]));

            return app(SecretEnvelope::class)->seal($request, $context, 'integration_api_key', $id.'|'.$secret);
        });

        $request->session()->put('integration_api_key', $sealed);

        return redirect('/integrations/api');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $ctx = app(TenantContext::class);
        abort_unless($ctx->brandId && preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1 && (strlen($id) < 19 || strcmp($id, (string) PHP_INT_MAX) <= 0), 404);
        $this->access->read($request, $ctx->brandId, 'integrations.write');
        $this->access->change($request, $ctx->brandId, function (Brand $brand, TenantContext $context) use ($id): void {
            $query = DB::table('personal_access_tokens')->where('organization_id', $context->organizationId)
                ->where('brand_id', $brand->id)->where('tokenable_type', Brand::class)->where('tokenable_id', $brand->id)->where('id', $id);
            $key = $query->lockForUpdate()->first();
            abort_unless($key, 404);
            $query->delete();
            app(AuditWriter::class)->record($context, new AuditEntry('integration.api_key.revoked', 'personal_access_token', $id, before: ['name' => $key->name]));
        });

        return redirect('/integrations/api');
    }
}
