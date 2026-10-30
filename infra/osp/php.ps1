param([Parameter(ValueFromRemainingArguments = $true)][string[]]$PhpArguments)
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$tempPath = Join-Path $projectRoot '.local/tmp'
[IO.Directory]::CreateDirectory($tempPath) | Out-Null
$phpPath = if ($env:SOUZPIR_PHP_BINARY) { $env:SOUZPIR_PHP_BINARY } else { 'C:/OSPanel/modules/PHP-8.4/php.exe' }
Push-Location $projectRoot
try {
    & $phpPath '-d' ('sys_temp_dir=' + $tempPath) @PhpArguments
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
