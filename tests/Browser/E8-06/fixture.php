<?php

use App\Models\Brand;
use App\Models\User;
use App\Tenancy\Cabinet;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Site\AuditRun;
use App\Vision\Site\SiteAction;
use App\Vision\SiteAudit\PublicAddressResolver;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
if (getenv('RUN_SITE_E2E') !== '1' || ! app()->environment('testing') || $db !== 'souzpir_test_sp_e8_06' || $db !== config('runtime.test_database') || $db === config('runtime.preview_database')) {
    throw new LogicException('Requires the explicitly enabled isolated E8-06 test database.');
}
$org = app(Cabinet::class)->organizationId();
$user = User::where('email', 'browser-auth@example.test')->where('name', 'Browser Fixture')->firstOrFail();
$brand = Brand::withoutGlobalScopes()->where('organization_id', $org)->where('name', 'Browser fixture')->firstOrFail();
$mode = $argv[1] ?? '';
if (str_starts_with($mode, 'theme:')) {
    $theme = substr($mode, 6);
    if (! in_array($theme, ['neutral', 'pyro', 'confectionery'], true)) {
        throw new LogicException('Unexpected theme.');
    }
    DB::table('brands')->where('organization_id', $org)->where('id', $brand->id)->update(['theme' => $theme]);
    exit(0);
}
if ($mode !== 'setup') {
    throw new LogicException('Unexpected fixture mode.');
}
$module = ['organization_id' => $org, 'brand_id' => $brand->id, 'module' => 'vision'];
if (DB::table('brand_modules')->where($module)->exists()) {
    DB::table('brand_modules')->where($module)->update(['enabled' => true, 'updated_at' => now()]);
} else {
    DB::table('brand_modules')->insert([...$module, 'id' => (string) Str::ulid(), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
}

config(['features.E8-01B.defaults.fetch_enabled' => true]);
app()->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
{
    public function addresses(string $host): array
    {
        if ($host !== 'fixture.example.com') {
            throw new RuntimeException('Unexpected fixture DNS host.');
        }

        return ['93.184.216.34'];
    }
});
Http::preventStrayRequests();
Http::fake(function ($request) {
    if (str_ends_with($request->url(), '/robots.txt')) {
        return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
    }
    if (rtrim($request->url(), '/') === 'https://fixture.example.com') {
        return Http::response('<html><body><h1>Тестовое предложение</h1><p>Данные HTML фикстуры.</p></body></html>', 200, ['Content-Type' => 'text/html']);
    }

    return Http::response('', 404, ['Content-Type' => 'text/plain']);
});
$result = app(SiteInspector::class)->inspect('https://fixture.example.com');
$ctx = new TenantContext($org, $brand->id, $user->id, source: 'job');
app(TenantContextRunner::class)->run($ctx, function () use ($user, $result): void {
    $id = '01J0000000000000000000E806';
    $run = AuditRun::query()->whereKey($id)->first() ?? new AuditRun;
    $run->forceFill(['id' => $id, 'created_by' => $user->id, 'result' => $result]);
    $run->save();
    foreach ([
        ['01J0000000000000000000E807', 'SP-E8-06: Проверить страницу', 'open', null, null],
        ['01J0000000000000000000E808', 'SP-E8-06: Обновить сведения', 'awaiting_measurement', '2026-10-31', 'Сведения обновлены'],
    ] as [$actionId, $title, $status, $completedAt, $comment]) {
        $action = SiteAction::query()->whereKey($actionId)->first() ?? new SiteAction;
        $action->forceFill(['id' => $actionId, 'title' => $title, 'description' => 'Тестовое действие', 'status' => $status, 'completed_at' => $completedAt, 'comment' => $comment]);
        $action->save();
    }
});
fwrite(STDOUT, "Isolated site fixture ready.\n");
