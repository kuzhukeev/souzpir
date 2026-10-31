<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedAuthDatabase;
use Tests\TestCase;

final class ArchivedSelectedBrandHttpTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        IsolatedAuthDatabase::assert();
    }

    public static function unavailableSelections(): array
    {
        return ['archived' => ['archive'], 'grant revoked' => ['grant']];
    }

    public static function uninitializedSelections(): array
    {
        return ['no selected brand' => [false], 'revoked legacy brand' => [true]];
    }

    public function test_remember_cookie_restores_a_fresh_lease_before_profile_tenant_resolution(): void
    {
        $viewer = User::create(['name' => 'Remembered viewer', 'email' => 'remember-selection@example.test', 'password' => 'race-password-strong']);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Synthetic cabinet']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($viewer): Brand {
            $brand = Brand::create(['name' => 'A']);
            Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$brand->id]]);

            return $brand;
        });
        $directory = base_path('.local/tmp/profile-remember-http-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        try {
            $base = ['directory' => $directory, 'organization' => $org->id, 'brand' => $brand->id, 'cookie' => '', 'email' => $viewer->email];
            $login = $this->request([...$base, 'operation' => 'login', 'remember' => true]);
            self::assertSame(302, $login['status']);
            self::assertIsString($login['cookie']);
            self::assertIsString($login['recaller']);
            self::assertSame(1, DB::table('auth_session_registry')->where('user_id', $viewer->id)->count());

            // Only the encrypted persistent-login cookie is sent; this is a
            // new file session, not the ordinary login's session cookie.
            $remembered = $this->request([...$base, 'operation' => 'profile', 'recaller' => $login['recaller']]);
            self::assertSame(200, $remembered['status']);
            self::assertIsString($remembered['cookie']);
            self::assertNotSame($login['cookie'], $remembered['cookie']);
            self::assertSame($brand->id, data_get($remembered, 'page.props.brand.id'));
            self::assertSame('Remembered viewer', data_get($remembered, 'page.props.profile.name'));
            self::assertSame(2, DB::table('auth_session_registry')->where('user_id', $viewer->id)->count());
            self::assertSame(1, DB::table('auth_session_registry')->where('user_id', $viewer->id)->whereNotNull('remember_fingerprint')->count());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    #[DataProvider('uninitializedSelections')]
    public function test_unsafe_profile_update_cannot_initialize_missing_lease_selection(bool $legacySnapshot): void
    {
        $viewer = User::create(['name' => 'Viewer', 'email' => 'uninitialized@example.test', 'password' => 'race-password-strong']);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Synthetic cabinet']);
        [$a, $b, $membership] = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($viewer): array {
            $a = Brand::create(['name' => 'A']);
            $b = Brand::create(['name' => 'B']);
            $member = Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$a->id, $b->id]]);

            return [$a, $b, $member];
        });
        $directory = base_path('.local/tmp/profile-uninitialized-http-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        try {
            $base = ['directory' => $directory, 'organization' => $org->id, 'brand' => $a->id, 'cookie' => '', 'email' => $viewer->email];
            $login = $this->request([...$base, 'operation' => 'login']);
            self::assertSame(302, $login['status']);
            self::assertIsString($login['cookie']);
            self::assertNull($login['session_brand']);
            self::assertIsString($login['fixture_revision']);
            $request = [...$base, 'cookie' => $login['cookie']];
            $revision = $login['fixture_revision'];

            if ($legacySnapshot) {
                // Reproduce a real file session created by an older deployment:
                // it selected A, but the newly added lease columns are still null.
                $page = $this->request([...$request, 'operation' => 'profile']);
                self::assertSame(200, $page['status']);
                self::assertSame($a->id, data_get($page, 'page.props.brand.id'));
                $revision = data_get($page, 'page.props.profileBrandRevision');
                self::assertIsString($revision);
                DB::table('auth_session_registry')->where('user_id', $viewer->id)->update([
                    'selected_organization_id' => null, 'selected_brand_id' => null, 'brand_revision' => 0,
                ]);
                DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => json_encode([$b->id], JSON_THROW_ON_ERROR)]);
            }

            self::assertNull(DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));
            $result = $this->request([...$request, 'operation' => 'update', 'revision' => $revision]);
            self::assertSame(404, $result['status']);
            self::assertNull(DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));
            self::assertNull(DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_organization_id'));
            self::assertSame(0, (int) DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('brand_revision'));
            self::assertSame('Viewer', $viewer->fresh()->name);
            self::assertSame(0, DB::table('profile_preferences')->where('user_id', $viewer->id)->count());
            self::assertSame(0, DB::table('audit_logs')->where('actor_id', $viewer->id)->where('action', 'profile.updated')->count());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    #[DataProvider('unavailableSelections')]
    public function test_viewer_recovers_from_unavailable_selection_without_retargeting_stale_mutations(string $cause): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner-selection@example.test', 'password' => 'race-password-strong']);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer-selection@example.test', 'password' => 'race-password-strong']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Synthetic cabinet', 'owner_user_id' => $owner->id]);
        [$a, $b, $membership] = app(TenantContextRunner::class)->run(new TenantContext($org->id), function () use ($owner, $viewer): array {
            $a = Brand::create(['name' => 'A', 'timezone' => 'Europe/Moscow']);
            $b = Brand::create(['name' => 'B', 'timezone' => 'UTC']);
            Membership::create(['user_id' => $owner->id, 'role' => 'owner']);
            $membership = Membership::create(['user_id' => $viewer->id, 'role' => 'viewer', 'brand_ids' => [$a->id, $b->id]]);

            return [$a, $b, $membership];
        });
        $directory = base_path('.local/tmp/profile-selection-http-'.Str::random(20));
        File::ensureDirectoryExists($directory);
        try {
            $base = ['directory' => $directory, 'organization' => $org->id, 'brand' => $a->id, 'cookie' => ''];
            $viewerLogin = $this->request([...$base, 'operation' => 'login', 'email' => $viewer->email]);
            self::assertSame(302, $viewerLogin['status']);
            self::assertIsString($viewerLogin['cookie']);
            $viewerRequest = [...$base, 'cookie' => $viewerLogin['cookie'], 'email' => $viewer->email];
            $viewerProfile = $this->request([...$viewerRequest, 'operation' => 'profile']);
            self::assertSame(200, $viewerProfile['status']);
            self::assertSame($a->id, data_get($viewerProfile, 'page.props.brand.id'));
            $oldRevision = data_get($viewerProfile, 'page.props.profileBrandRevision');
            self::assertIsString($oldRevision);
            self::assertSame($a->id, DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));

            if ($cause === 'archive') {
                $ownerLogin = $this->request([...$base, 'operation' => 'login', 'email' => $owner->email]);
                self::assertSame(302, $ownerLogin['status']);
                $ownerRequest = [...$base, 'cookie' => $ownerLogin['cookie'], 'email' => $owner->email];
                $ownerProfile = $this->request([...$ownerRequest, 'operation' => 'profile']);
                self::assertSame(200, $ownerProfile['status']);
                $ownerRevision = data_get($ownerProfile, 'page.props.profileBrandRevision');
                self::assertIsString($ownerRevision);
                self::assertSame(302, $this->request([...$ownerRequest, 'operation' => 'archive'])['status']);
                self::assertSame('archived', DB::table('brands')->where('id', $a->id)->value('status'));

                // Archiving the selected brand must not strand the owner either.
                $ownerAfter = $this->request([...$ownerRequest, 'operation' => 'profile']);
                self::assertSame(200, $ownerAfter['status']);
                self::assertSame($b->id, data_get($ownerAfter, 'page.props.brand.id'));
                self::assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $owner->id)->value('selected_brand_id'));
                self::assertSame(1, (int) DB::table('auth_session_registry')->where('user_id', $owner->id)->value('brand_revision'));
                $ownerStale = $this->request([...$ownerRequest, 'operation' => 'update', 'revision' => $ownerRevision]);
                self::assertContains($ownerStale['status'], [403, 404, 409]);
                self::assertSame('Owner', $owner->fresh()->name);
                self::assertSame(0, DB::table('audit_logs')->where('actor_id', $owner->id)->where('action', 'profile.updated')->count());
            } else {
                // Model a committed grant removal independently of the following HTTP requests.
                DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => json_encode([$b->id], JSON_THROW_ON_ERROR)]);
            }
            self::assertSame('active', DB::table('brands')->where('id', $b->id)->value('status'));

            // An explicit stale brand header may not silently return B or alter the lease.
            $wrongBrand = $this->request([...$viewerRequest, 'operation' => 'profile', 'brand_header' => $a->id]);
            self::assertContains($wrongBrand['status'], [403, 404, 409]);
            self::assertNotSame($b->id, data_get($wrongBrand, 'page.props.brand.id'));
            self::assertSame($a->id, DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));

            // A stale form is never silently redirected into B, before or after recovery.
            $stale = $this->request([...$viewerRequest, 'operation' => 'update', 'revision' => $oldRevision]);
            self::assertContains($stale['status'], [403, 404, 409]);
            self::assertSame($a->id, DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));
            self::assertSame('Viewer', $viewer->fresh()->name);
            self::assertSame(0, DB::table('audit_logs')->where('actor_id', $viewer->id)->where('action', 'profile.updated')->count());

            // The normal existing GET resumes the viewer's session with allowed brand B.
            $after = $this->request([...$viewerRequest, 'operation' => 'profile']);
            self::assertSame(200, $after['status']);
            self::assertSame($b->id, data_get($after, 'page.props.brand.id'));
            self::assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));
            self::assertSame(1, (int) DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('brand_revision'));
            $newRevision = data_get($after, 'page.props.profileBrandRevision');
            self::assertIsString($newRevision);
            self::assertNotSame($oldRevision, $newRevision);
            $stale = $this->request([...$viewerRequest, 'operation' => 'update', 'revision' => $oldRevision]);
            self::assertContains($stale['status'], [403, 404, 409]);
            self::assertSame('Viewer', $viewer->fresh()->name);
            self::assertSame(0, DB::table('audit_logs')->where('actor_id', $viewer->id)->where('action', 'profile.updated')->count());

            $saved = $this->request([...$viewerRequest, 'operation' => 'update', 'revision' => $newRevision]);
            self::assertSame(302, $saved['status']);
            self::assertSame('Updated viewer', $viewer->fresh()->name);
            self::assertSame(1, DB::table('audit_logs')->where('actor_id', $viewer->id)->where('action', 'profile.updated')->where('brand_id', $b->id)->count());

            // No accessible brand means fail closed and no lease selection changes.
            DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => '[]']);
            $none = $this->request([...$viewerRequest, 'operation' => 'profile']);
            self::assertContains($none['status'], [403, 404]);
            self::assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('selected_brand_id'));
            self::assertSame(1, (int) DB::table('auth_session_registry')->where('user_id', $viewer->id)->value('brand_revision'));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function request(array $data): array
    {
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/profile-selection-http-worker.php'];
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'SESSION_DRIVER' => 'file', 'PROFILE_SETTINGS_ENABLED' => 'true', 'CABINET_ORGANIZATION_ID' => $data['organization']]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR)."\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
