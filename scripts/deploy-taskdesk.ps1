param([string]$Target = 'C:\OSPanel\home\tasks.local')
$ErrorActionPreference='Stop'
$repoRoot=Split-Path -Parent $PSScriptRoot
$targetRoot=[IO.Path]::GetFullPath($Target).TrimEnd('\')
if ($targetRoot -cne 'C:\OSPanel\home\tasks.local') { throw 'Unexpected target; review this deployment script before changing target' }
$bundle=Join-Path $repoRoot 'tools\taskdesk'
$files=@('history.php','api.php','queue.php','app.js','index.html','style.css')
$hashes=Get-Content -Raw -LiteralPath (Join-Path $bundle 'source-hashes.json') | ConvertFrom-Json
foreach($entry in $hashes) {
    $path=Join-Path $targetRoot $entry.file
    $current=(Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash
    $desired=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $bundle $entry.file)).Hash
    if($current -ne $entry.sha256 -and $current -ne $desired) { throw ('Target changed since audit: '+$entry.file) }
}
$before=Invoke-RestMethod -Uri 'http://tasks.local/api.php?action=list'
$backup=Join-Path $repoRoot ('.local\taskdesk-backup-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backup | Out-Null
foreach($name in $files) {
    $path=Join-Path $targetRoot $name
    if(Test-Path -LiteralPath $path) { Copy-Item -LiteralPath $path -Destination (Join-Path $backup $name) }
}
Copy-Item -LiteralPath (Join-Path $targetRoot 'data\board.json') -Destination (Join-Path $backup 'board.json')
$before | ConvertTo-Json -Depth 100 | Set-Content -LiteralPath (Join-Path $backup 'api-before.json') -Encoding utf8
# Copy helper first so the api replacement never refers to an absent include.
foreach($name in @('history.php','queue.php','api.php','app.js','style.css','index.html')) {
    Copy-Item -LiteralPath (Join-Path $bundle $name) -Destination (Join-Path $targetRoot $name)
}
$after=Invoke-RestMethod -Uri 'http://tasks.local/api.php?action=list'
if($before.tasks.Count -ne $after.tasks.Count) { throw 'Task count changed during deployment; inspect backup before import' }
foreach($old in $before.tasks) {
    $fresh=@($after.tasks | Where-Object id -eq $old.id)
    if($fresh.Count -ne 1) { throw ('Missing task '+$old.id) }
    foreach($prop in $old.PSObject.Properties) {
        $a=ConvertTo-Json -InputObject $prop.Value -Depth 100 -Compress
        $b=ConvertTo-Json -InputObject $fresh[0].($prop.Name) -Depth 100 -Compress
        if($a -cne $b) { throw ('Legacy metadata changed '+$old.id+' / '+$prop.Name) }
    }
}
[PSCustomObject]@{backup=$backup;legacyTasksPreserved=$before.tasks.Count;deployedFiles=$files} | ConvertTo-Json
