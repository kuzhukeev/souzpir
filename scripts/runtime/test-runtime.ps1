param(
    [Parameter(Mandatory=$true)][ValidateSet('worker-once','scheduler-once','status')][string]$Action,
    [Parameter(Mandatory=$true)][string]$Php,
    [Parameter(Mandatory=$true)][string]$Database,
    [Parameter(Mandatory=$true)][int]$Port,
    [Parameter(Mandatory=$true)][string]$User
)
$ErrorActionPreference = 'Stop'
if ($Database -cnotmatch '^souzpir_test_[a-z0-9_]+$' -or $Port -lt 1 -or $Port -gt 65535 -or $User -notmatch '^[a-zA-Z0-9_]+$') { throw 'Укажите отдельную тестовую БД, порт и роль.' }
$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw 'PHP не найден.' }
$env:APP_ENV = 'testing'
$env:DB_URL = ''
$env:DB_CONNECTION = 'pgsql'
$env:DB_QUEUE_CONNECTION = 'pgsql'
$env:DB_QUEUE_TABLE = 'jobs'
$env:DB_FAILED_DRIVER = 'database-uuids'
$env:APP_PREVIEW_DATABASE = 'souzpir_preview_e007'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = "$Port"
$env:DB_DATABASE = $Database
$env:DB_TEST_DATABASE = $Database
$env:DB_USERNAME = $User
$env:QUEUE_CONNECTION = 'database'
$env:QUEUE_NAMESPACE = 'souzpir-test-e007'
$env:FEATURES_SCHEDULING_ENABLED = 'false'
$env:MAIL_MAILER = 'log'
$env:AI_DRIVER = 'fake'
$env:DELIVERY_DRIVER = 'fake'
$env:NOTIFICATIONS_EXTERNAL_ENABLED = 'false'
Push-Location $root
try {
    switch ($Action) {
        'worker-once' { & $Php artisan queue:work database --once --queue=souzpir-test-e007:default --tries=1 --no-interaction --env=testing }
        'scheduler-once' { & $Php artisan schedule:run --no-interaction --env=testing }
        'status' { & $Php artisan runtime:queue-status --no-interaction --env=testing }
    }
    exit $LASTEXITCODE
} finally { Pop-Location }
