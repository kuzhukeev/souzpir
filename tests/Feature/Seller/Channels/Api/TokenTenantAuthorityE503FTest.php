<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use App\Tenancy\TokenApi\BrandAccessToken;
use App\Tenancy\TokenApi\TokenTenantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TokenTenantAuthorityE503FTest extends TenancyTestCase
{
    public function test_rechecks_token_ability_revocation_and_brand_status(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => Brand::class, 'tokenable_id' => $brand->id, 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'name' => 'test', 'token' => hash('sha256', Str::random(64)),
            'token_last_four' => 'test', 'abilities' => json_encode(['leads']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = BrandAccessToken::query()->findOrFail($id);
        $request = Request::create('/api/v1/leads', 'POST');
        $request->setUserResolver(fn () => $brand);
        $request->attributes->set('tenant.token', $token);
        $tenant = new TenantContext($org->id, $brand->id, source: 'token');
        $authority = app(TokenTenantAuthority::class);
        self::assertSame($id, $authority->revalidateLocked($request, $tenant, 'leads')->id);

        $this->assertStatus(404, fn () => $authority->revalidateLocked($request, new TenantContext($org->id, $other->id, source: 'token'), 'leads'));
        DB::table('personal_access_tokens')->where('id', $id)->update(['abilities' => json_encode(['turn'])]);
        $this->assertStatus(403, fn () => $authority->revalidateLocked($request, $tenant, 'leads'));
        DB::table('personal_access_tokens')->where('id', $id)->update(['abilities' => json_encode(['leads'])]);
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
        $this->assertStatus(404, fn () => $authority->revalidateLocked($request, $tenant, 'leads'));
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'active']);
        DB::table('personal_access_tokens')->where('id', $id)->delete();
        $this->assertStatus(401, fn () => $authority->revalidateLocked($request, $tenant, 'leads'));
    }

    private function assertStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected rejection.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame($status, $error->getStatusCode());
        }
    }
}
