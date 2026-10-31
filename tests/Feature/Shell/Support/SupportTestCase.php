<?php

namespace Tests\Feature\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class SupportTestCase extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['features.E1-12.defaults.recipient' => null, 'features.E1-12.defaults.mail_enabled' => false, 'features.E1-12.defaults.smtp_enabled' => false]);
    }

    protected function user(): User
    {
        $user = User::create(['name' => 'Участник', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    protected function cabinet(): array
    {
        $owner = $this->user();
        $organization = Organization::create(['name' => 'Support fixture', 'owner_user_id' => $owner->id]);
        [$membership, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']),
            Brand::create(['name' => 'Бренд A', 'theme' => 'neutral']),
            Brand::create(['name' => 'Бренд B', 'theme' => 'neutral']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $organization->id]);

        return [$owner, $organization, $first, $second, $membership];
    }

    protected function member(Organization $org, string $role, array $brands, bool $manager = false): array
    {
        $user = $this->user();
        $member = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'),
            fn () => Membership::create(['user_id' => $user->id, 'role' => $role, 'brand_ids' => $brands, 'settings' => ['sales_manager' => $manager]]));

        return [$user, $member];
    }

    protected function signIn(User $user, Brand $brand): static
    {
        $user->refresh();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        app('session.store')->save();

        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    protected function ticket(array $extra = []): array
    {
        $response = $this->post('/help/support', [...$this->payload(), ...$extra], ['Accept' => 'application/json']);
        self::assertSame(201, $response->status());

        return $response->json('data');
    }

    protected function payload(): array
    {
        return ['subject' => 'technical', 'module' => 'cabinet', 'body' => 'Подробное описание проблемы пользователя.', 'support_access' => true];
    }

    protected function attachment(string $name = 'details.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "Приватный текст вложения.\n");
    }
}
