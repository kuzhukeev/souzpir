<?php
// Parallel project queue extension. PHP 7.4 compatible; all calls run under api.php's LOCK_EX.
function queueManaged($task) { return isset($task['project'], $task['taskKey']); }
function queueRevision($task) { return (int)($task['revision'] ?? 0); }
function queueCheckRevision($task, $input) {
    if (!isset($input['expectedRevision']) || (int)$input['expectedRevision'] !== queueRevision($task)) reply(['error'=>'Task changed; refresh before retrying'],409);
}
function queueTouch(&$task) { $task['revision']=queueRevision($task)+1; $task['updatedAt']=gmdate('c'); }
function queueFind($project, $key) { global $data; foreach($data['tasks'] as $i=>$t) if (($t['project']??null)===$project && ($t['taskKey']??null)===$key) return $i; return null; }
function queuePathsOverlap($a,$b) {
    if ($a==='/' || $b==='/') return true;
    $a=strtolower(rtrim($a,'/')); $b=strtolower(rtrim($b,'/'));
    return $a===$b || strpos($a,$b.'/')===0 || strpos($b,$a.'/')===0;
}
function queueBlockers($task) {
    global $data;
    $reasons=[];
    if (!queueManaged($task)) return $reasons;
    if (empty($data['projects'][$task['project']]['enabled'])) $reasons[]='project_disabled';
    foreach($task['dependsOn']??[] as $key) {
        $i=queueFind($task['project'],$key);
        if ($i===null || $data['tasks'][$i]['status']!=='done') $reasons[]='dependency:'.$key;
    }
    foreach($data['tasks'] as $other) {
        if (($other['project']??null)!==$task['project'] || $other['id']===$task['id'] || !in_array($other['status'],['progress','review'],true)) continue;
        $overlap=count(array_intersect($task['exclusiveResources']??[],$other['exclusiveResources']??[]))>0;
        foreach($task['scope']??[] as $a) foreach($other['scope']??[] as $b) if(queuePathsOverlap($a,$b)) $overlap=true;
        if($overlap) $reasons[]='ownership:'.$other['taskKey'];
    }
    return $reasons;
}
function queuePublic($task) {
    unset($task['claimTokenHash'], $task['planHash']);
    $task['revision']=queueRevision($task);
    if(queueManaged($task)) { $task['blockers']=queueBlockers($task); $task['ready']=$task['status']==='queue' && count($task['blockers'])===0; }
    return $task;
}
function queueStrings($value,$field) {
    if(!is_array($value)) reply(['error'=>'Invalid '.$field],400);
    foreach($value as $v) if(!is_string($v) || trim($v)==='' || strlen($v)>1000) reply(['error'=>'Invalid '.$field],400);
    return array_values(array_unique($value));
}
function queuePlan($item,$project) {
    if(!is_array($item)) reply(['error'=>'Invalid task plan'],400);
    $key=$item['taskKey']??'';
    if(!is_string($key) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D',$key)) reply(['error'=>'Invalid taskKey'],400);
    $task=normalizeTask(['title'=>$item['title']??'', 'description'=>$item['description']??'', 'status'=>'queue']);
    $task['project']=$project; $task['taskKey']=$key;
    $task['dependsOn']=queueStrings($item['dependsOn']??[],'dependsOn');
    $task['exclusiveResources']=queueStrings($item['exclusiveResources']??[],'exclusiveResources');
    $task['scope']=queueStrings($item['scope']??[],'scope');
    if(!$task['scope']) reply(['error'=>'Every project task needs a nonempty scope'],400);
    foreach($task['scope'] as &$path) {
        $path=str_replace('\\','/',$path);
        if(substr($path,-3)==='/**') $path=substr($path,0,-3).'/';
        if($path!=='/' && (strpos($path,'/')===0 || preg_match('/[:*?\x00]/',$path) || preg_match('#(^|/)\.\.?(/|$)#',$path))) reply(['error'=>'Scope must be repository-relative paths without globs'],400);
    } unset($path);
    foreach(['sourceRefs','acceptance','screenIds'] as $field) $task[$field]=queueStrings($item[$field]??[],$field);
    $task['wave']=(int)($item['wave']??0); $task['promptPath']=(string)($item['promptPath']??'');
    return $task;
}
function queueValidateGraph($tasks) {
    $byKey=[]; foreach($tasks as $task) $byKey[$task['taskKey']]=$task;
    $state=[];
    $visit=function($key) use (&$visit,&$state,$byKey) {
        if(($state[$key]??0)===1) reply(['error'=>'Dependency cycle at '.$key],400);
        if(($state[$key]??0)===2) return;
        if(!isset($byKey[$key])) reply(['error'=>'Unknown dependency '.$key],400);
        $state[$key]=1; foreach($byKey[$key]['dependsOn']??[] as $dep) $visit($dep); $state[$key]=2;
    };
    foreach(array_keys($byKey) as $key) $visit($key);
}
function queueRequireOwner($task,$input) {
    $token=(string)($input['claimToken']??'');
    if($token==='' || empty($task['claimTokenHash']) || !hash_equals($task['claimTokenHash'],hash('sha256',$token))) reply(['error'=>'Claim token does not own this task'],409);
}
function queueDispatch($action,$input) {
    global $data;
    if($action==='import') {
        $project=$input['project']??'';
        if(!is_string($project) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/D',$project) || !isset($input['tasks']) || !is_array($input['tasks']) || !$input['tasks']) reply(['error'=>'Invalid project import'],400);
        $plans=[]; $seen=[]; $existing=[]; $new=[];
        foreach($input['tasks'] as $item) {
            $task=queuePlan($item,$project); $key=$task['taskKey'];
            if(isset($seen[$key])) reply(['error'=>'Duplicate taskKey '.$key],400); $seen[$key]=true;
            $task['planHash']=hash('sha256',json_encode($task,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $idx=queueFind($project,$key);
            if($idx!==null) {
                if(($data['tasks'][$idx]['planHash']??'')!==$task['planHash']) reply(['error'=>'Existing plan differs: '.$key.'; import will not overwrite it'],409);
                $existing[]=$idx;
            } else $new[]=$task;
            $plans[$key]=$task;
        }
        foreach($data['tasks'] as $t) if(($t['project']??null)===$project && !isset($plans[$t['taskKey']])) $plans[$t['taskKey']]=$t;
        queueValidateGraph(array_values($plans));
        if(!isset($data['projects'][$project])) $data['projects'][$project]=['enabled'=>false,'createdAt'=>gmdate('c')];
        foreach($new as $task) { $task['id']=$data['nextId']++; $task['revision']=1; $task['createdAt']=gmdate('c'); $data['tasks'][]=$task; }
        saveData();
        $out=[]; foreach($data['tasks'] as $t) if(($t['project']??null)===$project && isset($seen[$t['taskKey']])) $out[]=queuePublic($t);
        reply(['project'=>$data['projects'][$project], 'created'=>count($new), 'existing'=>count($existing), 'tasks'=>$out]);
    }
    if($action==='project-mode') {
        $project=(string)($input['project']??'');
        if(!isset($data['projects'][$project]) || !isset($input['enabled']) || !is_bool($input['enabled'])) reply(['error'=>'Invalid project mode'],400);
        $data['projects'][$project]['enabled']=$input['enabled']; saveData(); reply(['project'=>$project,'enabled'=>$input['enabled']]);
    }
    if(!in_array($action,['claim','release','complete','submit','merge','requeue','recover','resume'],true)) return;
    $idx=isset($input['id'])?taskIndex((int)$input['id']):queueFind((string)($input['project']??''),(string)($input['taskKey']??''));
    if($idx===null || !queueManaged($data['tasks'][$idx])) reply(['error'=>'Project task not found'],404);
    $task=&$data['tasks'][$idx];
    if($action==='requeue' || $action==='recover') {
        queueCheckRevision($task,$input); $reason=trim((string)($input['reason']??''));
        $valid=$action==='requeue'?$task['status']==='review':($task['status']==='progress' && ($input['confirmAbandoned']??false)===true);
        if(!$valid || $reason==='') reply(['error'=>'Reason and valid state required; recover also requires confirmAbandoned=true'],409);
        $task['status']='queue'; $task['releaseReason']=$reason; unset($task['claimTokenHash'],$task['claimedAt'],$task['worker']); queueTouch($task); saveData(); reply(['task'=>queuePublic($task)]);
    }
    if($action==='claim') {
        $worker=trim((string)($input['worker']??''));
        if($worker==='' || strlen($worker)>240) reply(['error'=>'worker is required'],400);
        $blocked=queueBlockers($task);
        if($task['status']!=='queue' || $blocked) reply(['error'=>'Task is not ready','status'=>$task['status'],'blockers'=>$blocked],409);
        $token=bin2hex(random_bytes(32)); $task['claimTokenHash']=hash('sha256',$token); $task['worker']=$worker; $task['claimedAt']=gmdate('c'); $task['status']='progress'; queueTouch($task); saveData();
        reply(['task'=>queuePublic($task),'claimToken'=>$token]);
    }
    if($action==='merge') {
        queueCheckRevision($task,$input);
        $sha=(string)($input['mergeCommit']??'');
        if($task['status']!=='review' || !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$sha)) reply(['error'=>'Review state and full mergeCommit SHA are required'],409);
        $task['mergeCommit']=$sha; $task['mergedAt']=gmdate('c'); $task['status']='done'; unset($task['claimTokenHash'],$task['claimedAt']); queueTouch($task); saveData(); reply(['task'=>queuePublic($task)]);
    }
    queueRequireOwner($task,$input); queueCheckRevision($task,$input);
    if($action==='resume') {
        $reason=trim((string)($input['reason']??''));
        if($task['status']!=='review' || $reason==='') reply(['error'=>'Review state and reason required to resume'],409);
        $task['status']='progress'; $task['reviewFeedback']=$reason; queueTouch($task); saveData(); reply(['task'=>queuePublic($task)]);
    }
    if($task['status']!=='progress') reply(['error'=>'Task is not in progress'],409);
    if($action==='release') { $task['status']='queue'; $task['releaseReason']=(string)($input['reason']??''); }
    else {
        $sha=(string)($input['commit']??''); $branch=trim((string)($input['branch']??''));
        if(!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$sha) || $branch==='') reply(['error'=>'Full commit SHA and branch required for review'],400);
        $task['result']=['commit'=>$sha,'branch'=>$branch,'summary'=>(string)($input['summary']??''),'checks'=>queueStrings($input['checks']??[],'checks')]; $task['status']='review';
    }
    if($action==='release') unset($task['claimTokenHash'],$task['claimedAt']); queueTouch($task); saveData(); reply(['task'=>queuePublic($task)]);
}
