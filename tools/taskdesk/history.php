<?php
// Status events share the board lock and are committed in the same JSON write.
// Existing timestamps and transitions are never reconstructed from guesses.
function historyCapture($previousTasks, &$data) {
    $before=[];
    foreach ($previousTasks as $task) $before[(int)$task['id']]=$task;
    foreach ($data['tasks'] as &$task) {
        $id=(int)$task['id'];
        $old=$before[$id]??null;
        $from=$old['status']??null;
        $to=$task['status']??null;
        if ($old!==null && $from===$to) continue;
        if (!in_array($to,['queue','progress','review','done'],true)) continue;
        $at=gmdate('c');
        if ($old===null && empty($task['createdAt'])) $task['createdAt']=$at;
        $task['statusChangedAt']=$at;
        if (!isset($data['history'])) $data['history']=[];
        if (!isset($data['historyStartedAt'])) $data['historyStartedAt']=$at;
        $idSequence=(int)($data['historyNextId']??1);
        $data['historyNextId']=$idSequence+1;
        $task['statusChangedOrder']=$idSequence;
        $data['history'][]=['id'=>$idSequence,'taskId'=>$id,'taskKey'=>$task['taskKey']??null,'project'=>$task['project']??null,'title'=>$task['title'],'fromStatus'=>$from,'toStatus'=>$to,'at'=>$at];
    }
    unset($task);
}
