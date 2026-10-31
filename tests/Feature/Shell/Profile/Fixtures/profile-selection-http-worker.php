<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Shell\Auth\SessionBrandSelection;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatedAuthDatabase;

require __DIR__.'/../../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
IsolatedAuthDatabase::assert();
$data = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['session.files' => $data['directory'], 'session.lottery' => [0, 100]]);
Http::preventStrayRequests();
Mail::fake();
[$method, $uri, $parameters] = match ($data['operation']) {
    'login' => ['POST', '/login', ['email' => $data['email'], 'password' => 'race-password-strong', 'remember' => (bool) ($data['remember'] ?? false)]],
    'profile' => ['GET', '/profile', []],
    'update' => ['PUT', '/profile', ['name' => 'Updated viewer', 'phone' => null, 'timezone' => 'UTC', 'language' => 'ru']],
    'ready' => ['GET', '/auth/ready', []],
    'brands' => ['GET', '/brands', []],
    'switch' => ['POST', '/brand/switch', ['brand_id' => $data['brand']]],
    'archive' => ['PATCH', '/brands/'.$data['brand'].'/archive', ['confirm_name' => 'A']],
};
$request = Request::create($uri, $method, $parameters);
$request->server->set('SERVER_ADDR', '127.0.0.1');
if ($method === 'GET') {
    $request->headers->set('X-Inertia', 'true');
    $request->headers->set('X-Inertia-Version', app(HandleInertiaRequests::class)->version($request) ?? '');
}
if (isset($data['revision'])) {
    $request->headers->set('X-Brand-Revision', $data['revision']);
}
if (isset($data['brand_header'])) {
    $request->headers->set('X-Brand-Id', $data['brand_header']);
}
if ($data['cookie'] !== '') {
    $request->cookies->set(config('session.cookie'), base64_decode($data['cookie'], true));
}
if (isset($data['recaller'])) {
    $request->cookies->set(Auth::guard()->getRecallerName(), base64_decode($data['recaller'], true));
}
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$cookie = null;
$recaller = null;
foreach ($response->headers->getCookies() as $responseCookie) {
    if ($responseCookie->getName() === config('session.cookie')) {
        $cookie = base64_encode($responseCookie->getValue());
    }
    if ($responseCookie->getName() === Auth::guard()->getRecallerName()) {
        $recaller = base64_encode($responseCookie->getValue());
    }
}
echo json_encode([
    'status' => $response->getStatusCode(),
    'location' => $response->headers->get('Location'),
    'cookie' => $cookie,
    'recaller' => $recaller,
    'page' => json_decode($response->getContent(), true),
    // A fresh lease has revision 0. Generate a valid token for the actual file
    // session, so a missing header cannot conceal an unintended write.
    'fixture_revision' => $data['operation'] === 'login' ? app(SessionBrandSelection::class)->profileRevision($request, '0') : null,
    'session_brand' => $data['operation'] === 'login' ? $request->session()->get('brand_id') : null,
], JSON_THROW_ON_ERROR);
