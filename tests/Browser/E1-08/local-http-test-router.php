<?php

// Test-only CLI router. Never use this fixture as a production web entry point.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'local' || getenv('E108_LOCAL_HTTP_TEST') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['SERVER_PORT'] ?? '') !== '8248') {
    http_response_code(404);

    return;
}

// PHP's built-in server omits SERVER_ADDR. The test process must be bound to
// exactly this address; this fixture only supplies its known listening address.
$_SERVER['SERVER_ADDR'] = '127.0.1.11';

return require dirname(__DIR__, 3).'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
