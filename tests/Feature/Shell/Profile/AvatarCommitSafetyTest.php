<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Profile\ProfileController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class AvatarCommitSafetyTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    #[Group('race')]
    public function test_post_commit_exception_keeps_the_committed_private_avatar(): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $user = User::create(['name' => 'Test', 'email' => 'avatar-commit@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Test org', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => [Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Test brand'])]);
        Auth::login($user);
        session()->start();
        session()->put('auth.version', $user->auth_version);
        $request = Request::create('/profile/avatar', 'POST', [], [], ['avatar' => UploadedFile::fake()->image('avatar.png', 20, 20)]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $this->app->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                DB::afterCommit(static function (): void {
                    throw new \RuntimeException('synthetic post-commit failure');
                });
            }
        });
        $context = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        try {
            app(TenantContextRunner::class)->run($context, fn () => app(ProfileController::class)->storeAvatar($request));
            self::fail('Expected synthetic post-commit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Avatar update processing failed.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        $path = DB::table('profile_preferences')->where('user_id', $user->id)->value('avatar_path');
        self::assertIsString($path);
        Storage::disk('local')->assertExists($path);
        Http::assertNothingSent();
    }
}
