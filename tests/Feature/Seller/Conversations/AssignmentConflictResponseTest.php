<?php

namespace Tests\Feature\Seller\Conversations;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssignmentConflictResponseTest extends TestCase
{
    use DatabaseMigrations;

    public static function requestCases(): array
    {
        return [
            'json nested transaction' => ['json', false],
            'browser nested transaction' => ['browser', false],
            'private support boundary' => ['support', false],
            'direct PDO transaction' => ['json', true],
        ];
    }

    #[DataProvider('requestCases')]
    public function test_conflict_returns_safe_409_and_rolls_back_without_retry(string $mode, bool $directPdo): void
    {
        config(['app.debug' => true, 'database.connections.conflict_other' => config('database.connections.pgsql')]);
        $user = User::create(['name' => 'Original', 'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $org = Organization::create(['name' => 'Boundary fixture', 'owner_user_id' => $user->id]);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), function () use ($user): Brand {
            Membership::create(['user_id' => $user->id, 'role' => 'owner']);

            return Brand::create(['name' => 'A', 'theme' => 'neutral']);
        });
        $conversation = app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, source: 'job'), fn () => Conversation::create([]));
        $other = DB::connection('conflict_other');
        $other->beginTransaction();
        $other->select("SELECT pg_advisory_xact_lock(hashtextextended('seller-assignment-v1:' || ?, 0))", [$org->id]);
        $attempts = 0;
        $path = $mode === 'support' ? '/help/support/guard-fixture' : '/guard-fixture';
        Route::post($path, function () use ($user, $conversation, $directPdo, &$attempts): void {
            $attempts++;
            $connection = DB::connection();
            if ($directPdo) {
                $connection->getPdo()->beginTransaction();
            } else {
                $connection->beginTransaction();
            }
            DB::table('users')->where('id', $user->id)->update(['name' => 'Uncommitted change']);
            if (! $directPdo) {
                $connection->beginTransaction();
            }
            DB::table('conversations')->where('id', $conversation->id)->update(['assigned_user_id' => $user->id, 'summary' => 'SECRET-MARKER-DO-NOT-EXPOSE']);
        });
        try {
            $response = $mode === 'browser' ? $this->post($path) : $this->postJson($path);
            $response->assertStatus(409)->assertJson(['message' => 'Данные сейчас изменяются. Обновите страницу и повторите попытку.']);
            self::assertStringNotContainsString('SECRET-MARKER-DO-NOT-EXPOSE', $response->getContent());
            self::assertStringNotContainsString('SQLSTATE', $response->getContent());
            self::assertStringNotContainsString('UPDATE', $response->getContent());
            self::assertSame(1, $attempts);
            self::assertSame(0, DB::connection()->transactionLevel());
            self::assertFalse(DB::connection()->getPdo()->inTransaction());
            self::assertSame('Original', DB::table('users')->where('id', $user->id)->value('name'));
            self::assertNull(DB::table('conversations')->where('id', $conversation->id)->value('assigned_user_id'));
        } finally {
            $other->rollBack();
            DB::disconnect('conflict_other');
        }
    }
}
