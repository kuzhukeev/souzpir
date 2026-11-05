<?php

namespace Tests\Feature\ProtectedOperations;

use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\ProtectedOperations\JobFirstTurnInput;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Evaluation\DraftScenarioPreview;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    public function test_reviewed_fake_pair_requires_the_original_brand_persona_and_exact_text(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $draftId = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): string {
            app(SellerPersonaSeeder::class)->seedForBrand($tenant);
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);

            return app(KnowledgeRepository::class)->createDraft($tenant, $data)['id'];
        });
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => storage_path('framework/testing/e401o-empty')]);
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/e401o-fixture', function (Request $request) use ($draftId) {
            $context = app(TenantContext::class);
            $draft = app(KnowledgeRepository::class)->forRelease($context, $draftId, true);
            $runtime = app(FixtureRuntime::class);
            $result = $request->query('partial')
                ? $runtime->checkPartial($request, $draft, $request->query('client'), $request->query('answer'), ['compare_offer', 'full_card'], 'experienced_kazan')
                : $runtime->check($request, $draft, $request->query('client'), $request->query('answer'), ['compare_offer'], 'experienced_kazan');

            return response()->json(['result' => $result]);
        });
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->get('/e401w-dialogue', function (Request $request) use ($draftId) {
            $context = app(TenantContext::class);
            $draft = app(KnowledgeRepository::class)->forRelease($context, $draftId, true);
            $payload = DB::table('seller_personas')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('key', 'experienced_kazan')->value('payload');
            $persona = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            return response()->json(['turns' => app(DraftScenarioPreview::class)->dialogue($draft, $persona, 12, $request, 'experienced_kazan')]);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/e401w-dialogue')->assertOk()->assertJsonPath('turns.0.client', 'Уточню: Казань')->assertJsonFragment(['client' => 'У конкурента скидка 35%.']);
        $query = ['client' => 'У конкурента скидка 35%.', 'answer' => 'Можем сравнить предложения по конкретным позициям.'];
        $this->getJson('/e401o-fixture?'.http_build_query($query))->assertOk()->assertJsonPath('result.compare_offer', true);
        $partialQuery = $query + ['partial' => '1'];
        $this->getJson('/e401o-fixture?'.http_build_query($partialQuery))->assertOk()->assertJsonPath('result.compare_offer', true)->assertJsonPath('result.full_card', null);
        $query['answer'] = 'Сравнивать предложения не будем.';
        $this->getJson('/e401o-fixture?'.http_build_query($query))->assertOk()->assertJsonPath('result.compare_offer', false);
        $query['answer'] = 'Можем сравнить предложения по конкретным позициям. Изменено';
        $this->getJson('/e401o-fixture?'.http_build_query($query))->assertOk()->assertJsonPath('result', null);
        DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', 'experienced_kazan')->update(['payload' => '{}']);
        $query['answer'] = 'Можем сравнить предложения по конкретным позициям.';
        $this->getJson('/e401o-fixture?'.http_build_query($query))->assertOk()->assertJsonPath('result', null);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->getJson('/e401o-fixture?'.http_build_query($query))->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson('/e401o-fixture?'.http_build_query($query))->assertForbidden();
    }
}
