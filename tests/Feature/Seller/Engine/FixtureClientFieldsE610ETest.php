<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\CanonicalJson;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FixtureClientFieldsE610ETest extends TenancyTestCase
{
    public function test_published_profile_fields_survive_fake_extraction_and_stay_scoped(): void
    {
        config(['runtime.ai_driver' => 'fake']);
        $directory = storage_path('framework/testing/e610e-'.Str::ulid());
        mkdir($directory, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        [$owner, $org, $service, $school, $membership] = $this->cabinet();
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $base = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $base[$file] = $prototype->all($file);
        }
        $profiles = json_decode(file_get_contents(base_path('tests/fixtures/E6-10D/profiles.json')), true, flags: JSON_THROW_ON_ERROR);
        $values = [
            'service' => ['car_model' => 'Toyota Camry', 'car_year' => 2020, 'service_type' => 'Замена масла', 'preferred_date' => 'Пятница', 'branch' => 'Центральный'],
            'school' => ['course' => 'Английский', 'level' => 'Начальный', 'format' => 'Онлайн', 'preferred_time' => 'Вечером'],
        ];
        $runtime = app(FixtureRuntime::class);
        foreach (['service' => $service, 'school' => $school] as $key => $brand) {
            $data = $base;
            $data['policy']['client_fields'] = $profiles[$key]['fields'];
            $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
            $snapshot = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data) {
                $repo = app(KnowledgeRepository::class);
                $draft = $repo->createDraft($tenant, $data);

                return $repo->publish($tenant, $draft['id'], 1);
            });
            $message = 'Профиль '.$key;
            $body = $runtime->body($snapshot, 'extract', ['client' => $message, 'intents' => $snapshot->intentIds()]);
            $path = $directory.'/'.CanonicalJson::hash($body).'.json';
            $record = fn (array $slots) => file_put_contents($path, json_encode(['result' => ['content' => json_encode(['client_intent' => 'unclear', 'slot_updates' => $slots], JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
            $record($values[$key]);
            $state = new LeadState;
            $state->setStage('need');
            $policy = new Policy($snapshot);
            self::assertSame(array_key_first($values[$key]), $policy->nextSlot($state));
            $extraction = $runtime->extractPublished($snapshot, $message);
            self::assertIsArray($extraction);
            $policy->applyExtraction($state, $extraction);
            foreach ($values[$key] as $id => $value) {
                self::assertSame($value, $state->slot($id));
                self::assertStringContainsString($id.': '.$value, $state->cardText());
            }
            self::assertSame($extraction, $runtime->extractPublished($snapshot, $message));
            $foreign = $key === 'service' ? ['course' => 'Английский'] : ['car_model' => 'Toyota'];
            $record($foreign);
            self::assertNull($runtime->extractPublished($snapshot, $message));
            $record($key === 'service' ? ['car_year' => 'не число'] : ['course' => 123]);
            self::assertNull($runtime->extractPublished($snapshot, $message));
        }
    }
}
