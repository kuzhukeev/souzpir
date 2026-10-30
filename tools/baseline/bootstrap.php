<?php

declare(strict_types=1);

function baselineBoot(string $prototype): Illuminate\Foundation\Application
{
    $root = realpath($prototype);
    if (!$root || !is_file($root.'/vendor/autoload.php')) {
        throw new RuntimeException('Pass an isolated prototype copy with installed locked dependencies.');
    }
    if (str_contains(strtolower(str_replace('\\', '/', $root)), '/.local/references/')) {
        throw new RuntimeException('The read-only source reference cannot be booted. Use an isolated copy.');
    }
    if (is_file($root.'/.env')) {
        throw new RuntimeException('Offline baseline refuses a prototype copy containing .env.');
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'mail'] as $function) {
        if (!in_array($function, $disabled, true)) {
            throw new RuntimeException('Offline baseline requires disabled network/mail function: '.$function);
        }
    }
    if ((bool) ini_get('allow_url_fopen')) {
        throw new RuntimeException('Offline baseline requires allow_url_fopen=0.');
    }
    foreach (['APP_ENV'=>'testing', 'LLM_DRIVER'=>'fake', 'OPENROUTER_API_KEY'=>'', 'TELEGRAM_BOT_TOKEN'=>'',
        'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>':memory:', 'DB_URL'=>'', 'CACHE_STORE'=>'array',
        'SESSION_DRIVER'=>'array', 'QUEUE_CONNECTION'=>'sync', 'MAIL_MAILER'=>'array'] as $key=>$value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs'] as $directory) {
        if (!is_dir($root.'/'.$directory) && !mkdir($root.'/'.$directory, 0775, true)) {
            throw new RuntimeException('Cannot create isolated runtime directory.');
        }
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:',
        'agent.llm_driver'=>'fake', 'agent.openrouter.api_key'=>'', 'agent.telegram.token'=>'',
        'agent.knowledge_cache_ttl'=>0, 'cache.default'=>'array', 'session.driver'=>'array',
        'mail.default'=>'array']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Mail::fake();
    Carbon\Carbon::setTestNow('2026-10-31T09:00:00+03:00');
    return $app;
}
