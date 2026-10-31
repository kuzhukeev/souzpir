<?php

use App\Models\User;
use App\Shell\Auth\Http\PasswordController;
use App\Shell\Auth\PasswordDelivery\DeliveryClaim;
use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected || DB::connection()->getDatabaseName() !== config('runtime.test_database') || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
    throw new LogicException('Isolated test database required.');
}
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['features.E0-02.defaults.organization_id' => $data['org'], 'features.E1-01F.defaults.enabled' => true]);
echo 'ready:'.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
fgets(STDIN);
$user = User::findOrFail($data['user']);
switch ($data['mode']) {
    case 'reset':
        $request = Request::create('/reset-password', 'POST', ['email' => $user->email, 'token' => $data['token'], 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password']);
        $response = app(PasswordController::class)->store($request);
        echo str_ends_with($response->getTargetUrl(), '/login') ? 'reset' : 'denied';
        break;
    case 'revoke':
        DB::transaction(function () use ($data, $user): void {
            DB::table('organizations')->where('id', $data['org'])->lockForUpdate()->first();
            DB::table('memberships')->where('organization_id', $data['org'])->where('user_id', $user->id)->update(['status' => 'disabled']);
        });
        echo 'revoked';
        break;
    case 'claim':
        echo app(PasswordDelivery::class)->claim($data['id']) ? 'claimed' : 'skipped';
        break;
    case 'send':
        $transport = new class((bool) ($data['pause'] ?? false)) implements PasswordTransport
        {
            public int $calls = 0;

            public function __construct(private bool $pause) {}

            public function send(#[SensitiveParameter] User $user, #[SensitiveParameter] string $token): bool
            {
                $this->calls++;
                if ($this->pause) {
                    echo 'transmitting`n';
                    flush();
                    fgets(STDIN);
                }

                return true;
            }
        };
        app(PasswordDelivery::class)->send(new DeliveryClaim($data['id'], $data['claim']), $transport);
        echo 'calls:'.$transport->calls;
        break;
    default:
        throw new LogicException('Invalid test mode.');
}
