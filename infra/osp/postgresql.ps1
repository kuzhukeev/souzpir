param([ValidateSet('status', 'start', 'stop')][string]$Action = 'status')
$ErrorActionPreference = 'Stop'
$binaryRoot = 'C:/OSPanel/modules/PostgreSQL-16/bin'
$clusterPath = 'C:/OSPanel/data/PostgreSQL-16/default'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$runtimePath = Join-Path $projectRoot '.local/runtime/postgresql'
[IO.Directory]::CreateDirectory($runtimePath) | Out-Null
if ($Action -eq 'status') {
    & (Join-Path $binaryRoot 'pg_isready.exe') -h 127.0.1.48 -p 5432
    exit $LASTEXITCODE
}
if ($Action -eq 'stop') {
    # Shared local cluster: stop only after coordinating all active project tasks.
    & (Join-Path $binaryRoot 'pg_ctl.exe') -D $clusterPath -m fast -w stop
    exit $LASTEXITCODE
}
& (Join-Path $binaryRoot 'pg_isready.exe') -h 127.0.1.48 -p 5432 *> $null
if ($LASTEXITCODE -eq 0) { Write-Output 'PostgreSQL already accepts loopback connections'; exit 0 }
# pg_ctl uses the official restricted Windows token launcher for postgres.
# Do not use Start-Process -Wait: it waits for the entire server process tree.
$launcher = Start-Process -FilePath (Join-Path $binaryRoot 'pg_ctl.exe') -ArgumentList @('start', '-D', $clusterPath, '-l', (Join-Path $runtimePath 'postgresql.log'), '-w', '-t', '20') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'launcher.out.log') -RedirectStandardError (Join-Path $runtimePath 'launcher.err.log')
if (-not $launcher.WaitForExit(25000)) { throw 'pg_ctl did not exit within 25 seconds; inspect local logs without stopping the server.' }
if ($launcher.ExitCode -ne 0) { throw 'pg_ctl could not start PostgreSQL; inspect the local launcher logs.' }
& (Join-Path $binaryRoot 'pg_isready.exe') -h 127.0.1.48 -p 5432
exit $LASTEXITCODE
