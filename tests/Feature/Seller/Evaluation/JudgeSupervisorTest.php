<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Ai\CanonicalJson;
use App\Seller\Evaluation\DailySupervisor;
use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\Jobs\JudgeFakeJob;
use App\Seller\Evaluation\Judge;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JudgeSupervisorTest extends TenancyTestCase
{
    public function test_fake_judge_and_daily_report_are_idempotent_and_brand_scoped(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($tenant);
        $turns = [['client' => 'Здравствуйте', 'agent' => 'Добрый день', 'meta' => []]];
        $run = app(FakeSimulation::class)->run($tenant, 'newbie_voronezh', $turns);
        $persona = json_decode(DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', 'newbie_voronezh')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $request = app(Judge::class)->request($turns, $persona);
        $directory = storage_path('app/judge-'.Str::random(8));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $answer = ['naturalness' => 4, 'goal' => 4, 'facts' => 5, 'objections' => 3, 'brevity' => 4, 'comment' => 'Ответ краткий',
            'issues' => [['type' => 'repeated_question', 'layer' => 'policy', 'severity' => 'medium', 'turn' => 1, 'quote' => 'Добрый день', 'explanation' => 'Повтор', 'fix' => 'Не повторять']]];
        file_put_contents($directory.'/'.CanonicalJson::hash($request).'.json', json_encode(['result' => ['content' => json_encode($answer, JSON_UNESCAPED_UNICODE)]], JSON_THROW_ON_ERROR));
        JudgeFakeJob::dispatchSync($org->id, $brand->id, $run);
        $evaluation = DB::table('seller_evaluations')->where('sim_run_id', $run)->first();
        self::assertSame('4.00', $evaluation->average);
        self::assertSame('Повтор', json_decode($evaluation->issues, true)[0]['explanation']);
        JudgeFakeJob::dispatchSync($org->id, $brand->id, $run);
        self::assertSame(1, DB::table('seller_evaluations')->where('sim_run_id', $run)->count());
        try {
            app(Judge::class)->evaluate(new TenantContext($org->id, $other->id, source: 'job'), $run);
            self::fail('Foreign run accepted');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
        $otherTenant = new TenantContext($org->id, $other->id, source: 'job');
        app(SellerPersonaSeeder::class)->seedForBrand($otherTenant);
        $otherRun = app(FakeSimulation::class)->run($otherTenant, 'newbie_voronezh', $turns);
        $otherEvaluation = app(Judge::class)->evaluate($otherTenant, $otherRun);
        $day = CarbonImmutable::parse('2026-11-01', 'Europe/Moscow');
        DB::table('seller_evaluations')->whereIn('id', [$evaluation->id, $otherEvaluation])->update(['created_at' => $day->addHours(12)->utc()]);
        self::assertSame(2, app(DailySupervisor::class)->run($day));
        self::assertSame(0, app(DailySupervisor::class)->run($day));
        $summary = DB::table('seller_evaluations')->where('brand_id', $brand->id)->where('comment', 'supervisor:2026-11-01')->first();
        self::assertSame($brand->id, $summary->brand_id);
        self::assertSame(1, DB::table('seller_evaluations')->where('brand_id', $other->id)->where('comment', 'supervisor:2026-11-01')->count());
        self::assertSame(1, json_decode($summary->scores, true)['by_layer']['policy']);
        self::assertSame(1, json_decode($summary->issues, true)[0]['count']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.supervisor.completed', 'subject_id' => $summary->id]);
        Http::assertNothingSent();
    }

    public function test_supervisor_is_scheduled_daily_only_when_enabled(): void
    {
        config(['runtime.features_scheduling_enabled' => true]);
        require base_path('routes/console.php');
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($item) => $item->description === 'seller-evaluation-supervisor');
        self::assertNotNull($event);
        self::assertSame('0 0 * * *', $event->expression);
        self::assertSame('Europe/Moscow', $event->timezone);
    }

    public function test_failed_run_and_live_driver_are_rejected(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($tenant);
        $run = app(FakeSimulation::class)->run($tenant, 'newbie_voronezh', [['client' => 'Вопрос', 'agent' => 'Ответ']]);
        DB::table('seller_sim_runs')->where('id', $run)->update(['status' => 'failed']);
        config(['runtime.ai_driver' => 'fake']);
        try {
            app(Judge::class)->evaluate($tenant, $run);
            self::fail('Failed run accepted');
        } catch (LogicException) {
            self::assertDatabaseCount('seller_evaluations', 0);
        }
        config(['runtime.ai_driver' => 'openrouter']);
        $this->expectException(LogicException::class);
        app(Judge::class)->evaluate($tenant, $run);
    }
}
