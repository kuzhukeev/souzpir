param([string]$Php = 'C:\OSPanel\modules\PHP-7.4\php.exe')
$ErrorActionPreference = 'Stop'
$fixture = Join-Path $PSScriptRoot ('test-run-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $fixture 'data') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'api.php'),(Join-Path $PSScriptRoot 'queue.php') -Destination $fixture
$legacy = @{nextId=2;tasks=@(@{id=1;title='Legacy';description='Untouched';status='done';images=@();customField='preserved'})}
[IO.File]::WriteAllText((Join-Path $fixture 'data\board.json'),($legacy | ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding $false))
function FreePort { $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0); $listener.Start(); $port=$listener.LocalEndpoint.Port; $listener.Stop(); return $port }
$ports=@((FreePort),(FreePort)); $servers=@(); $client=[Net.Http.HttpClient]::new(); $client.Timeout=[TimeSpan]::FromSeconds(10)
function CallQueue($action,$body,$port=$ports[0]) {
    $uri="http://127.0.0.1:$port/api.php?action=$action"
    if($null -eq $body) { $res=$client.GetAsync($uri).GetAwaiter().GetResult() }
    else { $payload=[Net.Http.StringContent]::new(($body | ConvertTo-Json -Depth 30 -Compress),[Text.Encoding]::UTF8,'application/json'); $res=$client.PostAsync($uri,$payload).GetAwaiter().GetResult() }
    $raw=$res.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    try { $obj=$raw | ConvertFrom-Json } catch { throw "Invalid JSON: $raw" }
    return @{status=[int]$res.StatusCode;body=$obj}
}
function Assert($condition,$label) { if(!$condition) { throw "FAIL: $label" }; Write-Output "PASS: $label" }
try {
    foreach($port in $ports) { $servers += Start-Process -FilePath $Php -ArgumentList @('-n','-S',"127.0.0.1:$port",'-t',('"'+$fixture+'"')) -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $fixture "$port.out.log") -RedirectStandardError (Join-Path $fixture "$port.err.log") }
    $started=$false
    for($attempt=0;$attempt -lt 30;$attempt++) { try { $ping=CallQueue 'list' $null; $started=$ping.status -eq 200; if($started){break} } catch {}; Start-Sleep -Milliseconds 100 }
    Assert $started 'fixture HTTP servers start'
    $manifest=@{project='test';tasks=@(
        @{taskKey='A';title='A';scope=@('backend/Auth/');dependsOn=@();exclusiveResources=@('schema')},
        @{taskKey='B';title='B';scope=@('frontend/Auth/');dependsOn=@()},
        @{taskKey='C';title='C';scope=@('backend/Auth/Test.php');dependsOn=@()},
        @{taskKey='D';title='D';scope=@('tests/Integration/');dependsOn=@('A')}
    )}
    $import=CallQueue 'import' $manifest; Assert ($import.status -eq 200 -and $import.body.created -eq 4) 'atomic import'
    $again=CallQueue 'import' $manifest; Assert ($again.body.created -eq 0 -and $again.body.existing -eq 4) 'idempotent import'
    $blocked=CallQueue 'claim' @{project='test';taskKey='A';worker='worker-1'}; Assert ($blocked.status -eq 409 -and $blocked.body.blockers -contains 'project_disabled') 'preparation cannot start disabled project'
    $enable=CallQueue 'project-mode' @{project='test';enabled=$true}; Assert ($enable.status -eq 200) 'explicit project activation'
    $pending=@(); $contents=@()
    for($n=0;$n -lt 12;$n++) {
        $payload=[Net.Http.StringContent]::new((@{project='test';taskKey='A';worker="race-$n"} | ConvertTo-Json -Compress),[Text.Encoding]::UTF8,'application/json'); $contents += $payload
        $pending += $client.PostAsync("http://127.0.0.1:$($ports[$n%2])/api.php?action=claim",$payload)
    }
    $responses=@(); foreach($p in $pending){$responses += $p.GetAwaiter().GetResult()}
    $winners=@($responses | Where-Object { [int]$_.StatusCode -eq 200 }); $losers=@($responses | Where-Object { [int]$_.StatusCode -eq 409 })
    Assert ($winners.Count -eq 1 -and $losers.Count -eq 11) '12 concurrent claims on two PHP processes produce one winner'
    $a=$winners[0].Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
    $b=CallQueue 'claim' @{project='test';taskKey='B';worker='independent'}; Assert ($b.status -eq 200) 'independent scope runs concurrently'
    $c=CallQueue 'claim' @{project='test';taskKey='C';worker='overlap'}; Assert ($c.status -eq 409 -and $c.body.blockers -contains 'ownership:A') 'overlapping scope blocked'
    $resourceImport=CallQueue 'import' @{project='test';tasks=@(@{taskKey='R';title='Shared resource';scope=@('elsewhere/');dependsOn=@();exclusiveResources=@('schema')})}
    $resource=CallQueue 'claim' @{project='test';taskKey='R';worker='shared-resource'}; Assert ($resource.status -eq 409 -and $resource.body.blockers -contains 'ownership:A') 'exclusive resource blocks disjoint paths'
    $d=CallQueue 'claim' @{project='test';taskKey='D';worker='dependent'}; Assert ($d.status -eq 409 -and $d.body.blockers -contains 'dependency:A') 'dependency blocked'
    $list=CallQueue 'list' $null; $listedA=$list.body.tasks | Where-Object taskKey -eq A
    Assert (!$listedA.PSObject.Properties['claimTokenHash'] -and !$listedA.PSObject.Properties['claimToken']) 'list never exposes claim token'
    $bypass=CallQueue 'update' @{id=$a.task.id;title='A';status='done';expectedRevision=$a.task.revision}; Assert ($bypass.status -eq 409) 'legacy update cannot bypass workflow'
    $wrong=CallQueue 'release' @{id=$a.task.id;claimToken='wrong';expectedRevision=$a.task.revision}; Assert ($wrong.status -eq 409) 'wrong claim token rejected'
    $submit=CallQueue 'submit' @{id=$a.task.id;claimToken=$a.claimToken;expectedRevision=$a.task.revision;commit=('a'*40);branch='task/A';summary='Ready';checks=@('fixture')}
    Assert ($submit.status -eq 200 -and $submit.body.task.status -eq 'review') 'completed branch waits in review'
    $reviewOverlap=CallQueue 'claim' @{project='test';taskKey='C';worker='overlap-review'}; Assert ($reviewOverlap.status -eq 409 -and $reviewOverlap.body.blockers -contains 'ownership:A') 'review retains file ownership until merge'
    $d=CallQueue 'claim' @{project='test';taskKey='D';worker='dependent'}; Assert ($d.status -eq 409) 'review does not unlock dependents'
    $resume=CallQueue 'resume' @{id=$a.task.id;claimToken=$a.claimToken;expectedRevision=$submit.body.task.revision;reason='Fix review comment'}; Assert ($resume.status -eq 200 -and $resume.body.task.status -eq 'progress') 'original owner resumes review with preserved token'
    $submit=CallQueue 'submit' @{id=$a.task.id;claimToken=$a.claimToken;expectedRevision=$resume.body.task.revision;commit=('c'*40);branch='task/A';summary='Fixed';checks=@('fixture')}; Assert ($submit.status -eq 200) 'resubmission keeps ownership through review'
    $merge=CallQueue 'merge' @{id=$a.task.id;expectedRevision=$submit.body.task.revision;mergeCommit=('b'*40)}
    Assert ($merge.status -eq 200 -and $merge.body.task.status -eq 'done') 'merge records integrated commit'
    $ready=CallQueue 'ready&project=test' $null; Assert (($ready.body.tasks | Where-Object taskKey -eq D).ready) 'only merge unlocks dependency'
    $release=CallQueue 'release' @{id=$b.body.task.id;claimToken=$b.body.claimToken;expectedRevision=$b.body.task.revision;reason='retry later'}; Assert ($release.status -eq 200 -and $release.body.task.status -eq 'queue') 'owner release returns to queue'
    $update=CallQueue 'update' @{id=1;title='Legacy';description='Untouched';status='done';expectedRevision=0}; Assert ($update.status -eq 200 -and $update.body.task.customField -eq 'preserved') 'legacy metadata survives update'
    $stale=CallQueue 'update' @{id=1;title='Stale';status='done';expectedRevision=0}; Assert ($stale.status -eq 409) 'optimistic revision rejects stale update'
    $cycle=CallQueue 'import' @{project='cycle';tasks=@(@{taskKey='X';title='X';scope=@('x/');dependsOn=@('Y')},@{taskKey='Y';title='Y';scope=@('y/');dependsOn=@('X')})}; Assert ($cycle.status -eq 400) 'dependency cycle rejected'
    $missing=CallQueue 'import' @{project='missing';tasks=@(@{taskKey='X';title='X';scope=@('x/');dependsOn=@('Y')})}; Assert ($missing.status -eq 400) 'missing dependency rejected'
    $manifest.tasks[0].title='Changed'; $changed=CallQueue 'import' $manifest; Assert ($changed.status -eq 409) 'reimport cannot overwrite changed plan'
    $final=CallQueue 'list' $null; Assert ($final.body.tasks.Count -eq 6) 'failed transactions leave no partial tasks'
    $badScope=CallQueue 'import' @{project='bad';tasks=@(@{taskKey='X';title='X';scope=@('../outside/');dependsOn=@()})}; Assert ($badScope.status -eq 400) 'scope traversal rejected'
    $recoverClaim=CallQueue 'claim' @{project='test';taskKey='B';worker='abandoned'}
    $badRecover=CallQueue 'recover' @{id=$recoverClaim.body.task.id;expectedRevision=$recoverClaim.body.task.revision;reason='fixture'}; Assert ($badRecover.status -eq 409) 'recovery requires explicit abandoned acknowledgement'
    $recover=CallQueue 'recover' @{id=$recoverClaim.body.task.id;expectedRevision=$recoverClaim.body.task.revision;reason='fixture worker stopped';confirmAbandoned=$true}; Assert ($recover.status -eq 200 -and $recover.body.task.status -eq 'queue') 'explicit recovery releases stopped worker'
    Write-Output "TESTS PASSED. Fixture: $fixture"
} finally { $client.Dispose(); foreach($server in $servers) { if(!$server.HasExited) { Stop-Process -Id $server.Id -Force } } }
