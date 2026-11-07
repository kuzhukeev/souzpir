<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class TeamTestCase extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        // Fail before bootstrapping if Composer resolves a different checkout.
        $expected = realpath(dirname(__DIR__, 4));
        $cwd = realpath(getcwd());
        $inferred = realpath(Application::inferBasePath());
        $samePath = static fn (string $a, string $b): bool => DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($a, $b) === 0 : strcmp($a, $b) === 0;
        if (! $expected || ! $cwd || ! $inferred || ! $samePath($expected, $cwd) || ! $samePath($expected, $inferred)) {
            throw new \RuntimeException('Team tests require the current worktree as application root.');
        }

        $app = parent::createApplication();
        $base = realpath($app->basePath());
        if (! $base || ! $samePath($expected, $base)) {
            throw new \RuntimeException('Team test application root differs from the current worktree.');
        }

        return $app;
    }

    protected function beforeRefreshingDatabase(): void
    {
        $test = config('runtime.test_database');
        $preview = config('runtime.preview_database');
        $connection = DB::connection();
        $configured = $connection->getDatabaseName();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || ! is_string($test) || ! is_string($preview) || $preview === ''
            || ! is_string($configured) || ! preg_match('/(^|_)test(_|$)/i', $configured)
            || $configured !== $test || $configured === $preview) {
            throw new \RuntimeException('Team tests require a separate, explicitly configured PostgreSQL test database.');
        }
        $name = $connection->selectOne('select current_database() as name')->name ?? null;
        if ($name !== $configured) {
            throw new \RuntimeException('Team tests require a separate, explicitly configured PostgreSQL test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
    }

    protected function user(string $name = 'Участник'): User
    {
        $user = User::create(['name' => $name, 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    protected function cabinet(): array
    {
        $owner = $this->user('Владелец');
        $org = Organization::create(['name' => 'Team fixture', 'owner_user_id' => $owner->id]);
        [$membership, $first, $second] = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => [
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']),
            Brand::create(['name' => 'Бренд A', 'theme' => 'neutral']),
            Brand::create(['name' => 'Бренд B', 'theme' => 'neutral']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);

        return [$owner, $org, $first, $second, $membership];
    }

    protected function member(Organization $org, string $role, array $brands, bool $manager = false): array
    {
        $user = $this->user($manager ? 'Менеджер' : $role);
        $membership = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'),
            fn () => Membership::create(['user_id' => $user->id, 'role' => $role, 'brand_ids' => $brands, 'settings' => ['sales_manager' => $manager, 'preserved' => 'value']]));

        return [$user, $membership];
    }

    protected function signIn(User $user, Brand $brand): static
    {
        $user->refresh();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        app('session.store')->save();

        return $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    protected function invitation(string $email, string $role, array $brands): array
    {
        $response = $this->postJson('/team/invitations', ['email' => $email, 'role' => $role, 'brand_ids' => $brands]);
        self::assertSame(201, $response->status());

        return $response->json('data');
    }

    protected function token(array $issued): string
    {
        return (string) parse_url($issued['invitation_url'], PHP_URL_FRAGMENT);
    }
}
