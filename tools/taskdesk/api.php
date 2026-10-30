<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!(strpos($remote, '127.') === 0 || $remote === '::1')) { http_response_code(403); exit('Local access only'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = parse_url($_SERVER['HTTP_ORIGIN']);
    $serverOrigin = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''));
    if (($origin['host'] ?? '') !== ($serverOrigin['host'] ?? '') || (int)($origin['port'] ?? 80) !== (int)($serverOrigin['port'] ?? 80)) { http_response_code(403); exit('Origin denied'); }
}
$root = __DIR__;
$dataDir = $root . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
$dbPath = $dataDir . '/board.json';
$handle = fopen($dbPath, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) { http_response_code(500); exit('Storage unavailable'); }
$contents = stream_get_contents($handle);
$data = json_decode($contents, true);
if ($contents === '') $data = ['nextId'=>1, 'tasks'=>[]];
elseif (!is_array($data) || !isset($data['tasks'], $data['nextId'])) { http_response_code(500); exit('Invalid storage data'); }
function reply($body, $code=200) { global $handle; flock($handle, LOCK_UN); fclose($handle); http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function saveData() { global $handle, $data, $dbPath; $encoded=json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); if($encoded===false) reply(['error'=>'Invalid storage encoding'],500); rewind($handle); $previous=stream_get_contents($handle); if($previous!=='' && file_put_contents($dbPath.'.bak',$previous)===false) reply(['error'=>'Storage backup failed'],500); rewind($handle); if (!ftruncate($handle,0) || fwrite($handle,$encoded)!==strlen($encoded) || !fflush($handle)) reply(['error'=>'Storage write failed; previous version is in board.json.bak'],500); }
function taskIndex($id) { global $data; foreach ($data['tasks'] as $i=>$task) if ((int)$task['id'] === $id) return $i; return null; }
function normalizeTask($input, $old = null) {
    $title = trim((string)($input['title'] ?? '')); $status = $input['status'] ?? 'queue';
    if ($title === '' || strlen($title) > 1200 || !in_array($status,['queue','progress','review','done'],true)) reply(['error'=>'Invalid task'],400);
    $task = $old ?? ['images'=>[]]; $task['title']=$title; $task['description']=(string)($input['description'] ?? ''); $task['status']=$status;
    if (isset($input['imageIds']) && is_array($input['imageIds'])) $task['images']=array_values(array_filter($task['images'], function($img) use ($input) { return in_array($img['id'],$input['imageIds'],true); }));
    return $task;
}
function completeFiles(&$task) {
    global $root;
    if ($task['status'] !== 'done') return;
    $dir = $root . '/completed/' . (int)$task['id'];
    if (!is_dir($dir) && !mkdir($dir,0775,true)) reply(['error'=>'Cannot create completed directory'],500);
    foreach ($task['images'] as &$img) {
        if (($img['folder'] ?? '') === 'completed') continue;
        $src = $root . '/attachments/' . (int)$task['id'] . '/' . $img['file'];
        $dst = $dir . '/' . $img['file'];
        if (!is_file($src) || (!is_file($dst) && !copy($src,$dst))) reply(['error'=>'Cannot copy attachment'],500);
        $img['folder']='completed';
    } unset($img);
}
require_once __DIR__.'/queue.php';
$action = $_GET['action'] ?? 'list';
if ($method === 'GET' && $action === 'list') reply(['tasks'=>array_map('queuePublic',$data['tasks']),'projects'=>$data['projects']??[]]);
if ($method === 'GET' && $action === 'ready') { $project=(string)($_GET['project']??''); $ready=[]; foreach($data['tasks'] as $t) { $t=queuePublic($t); if(($t['project']??null)===$project && !empty($t['ready'])) $ready[]=$t; } reply(['tasks'=>$ready]); }
if ($method === 'GET' && $action === 'image') {
    $idx=taskIndex((int)($_GET['task'] ?? 0)); $imageId=(string)($_GET['file'] ?? '');
    if ($idx === null) reply(['error'=>'Not found'],404);
    foreach ($data['tasks'][$idx]['images'] as $img) if (hash_equals($img['id'],$imageId)) {
        $path=$root.'/'.$img['folder'].'/'.(int)$data['tasks'][$idx]['id'].'/'.$img['file'];
        if (!is_file($path)) reply(['error'=>'Image not found'],404);
        $mime = mime_content_type($path); if (strpos((string)$mime,'image/')!==0) reply(['error'=>'Invalid image'],400);
        flock($handle,LOCK_UN); fclose($handle); header('Content-Type: '.$mime); header('Cache-Control: private, max-age=3600'); readfile($path); exit;
    }
    reply(['error'=>'Image not found'],404);
}
if ($method !== 'POST') reply(['error'=>'Method not allowed'],405);
if ($action === 'upload') {
    $idx=taskIndex((int)($_POST['id'] ?? 0)); if ($idx===null) reply(['error'=>'Task not found'],404);
    if(queueManaged($data['tasks'][$idx])) reply(['error'=>'Use sourceRefs for project task references'],409);
    $file=$_FILES['image'] ?? null; if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) reply(['error'=>'Upload failed; check PHP upload limit'],400);
    $info=@getimagesize($file['tmp_name']); $exts=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
    if (!$info || !isset($exts[$info['mime']])) reply(['error'=>'Only PNG, JPEG, GIF and WebP images are supported'],400);
    $task=&$data['tasks'][$idx]; $folder=$task['status']==='done'?'completed':'attachments'; $dir=$root.'/'.$folder.'/'.(int)$task['id'];
    if (!is_dir($dir) && !mkdir($dir,0775,true)) reply(['error'=>'Cannot create folder'],500);
    $id=bin2hex(random_bytes(16)); $original=pathinfo((string)$file['name'],PATHINFO_FILENAME); $label=substr(trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $original),'._-'),0,90); $name=substr($id,0,8).'-'.($label ?: 'image').'.'.$exts[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) reply(['error'=>'Cannot save image'],500);
    $image=['id'=>$id,'file'=>$name,'name'=>basename(str_replace('\\','/',(string)$file['name'])),'folder'=>$folder]; $task['images'][]=$image; queueTouch($task); saveData(); reply(['image'=>$image]);
}
$input=json_decode(file_get_contents('php://input'),true); if (!is_array($input)) reply(['error'=>'Invalid request'],400);
queueDispatch($action,$input);
if ($action==='create') {
    if (!empty($input['legacyKey'])) foreach ($data['tasks'] as $task) if (($task['legacyKey']??null)===$input['legacyKey']) reply(['task'=>$task]);
    $task=normalizeTask($input); $task['id']=$data['nextId']++; if (!empty($input['legacyKey'])) $task['legacyKey']=(string)$input['legacyKey']; completeFiles($task); $data['tasks'][]=$task; saveData(); reply(['task'=>$task]);
}
$id=(int)($input['id'] ?? 0); $idx=taskIndex($id); if ($idx===null) reply(['error'=>'Task not found'],404);
if ($action==='update') { $old=$data['tasks'][$idx]; if(queueManaged($old)) { queueCheckRevision($old,$input); if(($input['status']??'queue')!==$old['status']) reply(['error'=>'Use claim, submit, release or merge for project task transitions'],409); } elseif(isset($input['expectedRevision'])) queueCheckRevision($old,$input); $task=normalizeTask($input,$old); completeFiles($task); queueTouch($task); $data['tasks'][$idx]=$task; saveData(); reply(['task'=>queuePublic($task)]); }
if ($action==='delete') { if(queueManaged($data['tasks'][$idx])) reply(['error'=>'Project plans cannot be deleted through the legacy editor'],409); array_splice($data['tasks'],$idx,1); saveData(); reply(['ok'=>true]); }
reply(['error'=>'Unknown action'],404);
