<?php

namespace Tests\Feature\ProtectedOperations;

use App\ProtectedOperations\JobFirstTurnInput;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JobFirstTurnInputE306FTest extends TenancyTestCase
{
    public function test_job_resolves_persisted_first_message_and_rejects_other_contexts(): void
    {
        [$user, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Сохранённое сообщение', 'meta' => []]);

            return [$conversation, $message];
        });
        $resolver = app(JobFirstTurnInput::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $command = $resolver->resolve($tenant, $conversation->id, $message->id, 'first-turn');
        self::assertSame('Сохранённое сообщение', $command->text);
        self::assertSame(0, $command->expectedVersion);
        foreach ([new TenantContext($org->id, $other->id, source: 'job'), new TenantContext($org->id, $brand->id, source: 'session')] as $invalid) {
            try {
                $resolver->resolve($invalid, $conversation->id, $message->id, 'first-turn');
                self::fail('Unexpectedly accepted context.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        foreach ([['invalid', $message->id, 'key'], [$conversation->id, $message->id, '']] as [$c, $m, $key]) {
            try {
                $resolver->resolve($tenant, $c, $m, $key);
                self::fail('Unexpectedly accepted input.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        config(['runtime.ai_driver' => 'live']);
        $this->expectException(LogicException::class);
        $resolver->resolve($tenant, $conversation->id, $message->id, 'first-turn');
    }
}
