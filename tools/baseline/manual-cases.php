<?php

declare(strict_types=1);

return [
    ['id'=>'manual.opt_out', 'title'=>'Отказ от дальнейших сообщений', 'inputs'=>[['do_not_disturb'=>true, 'client_intent'=>'opt_out']]],
    ['id'=>'manual.prompt_injection', 'title'=>'Попытка подменить инструкции', 'inputs'=>[['prompt_injection'=>true, 'client_intent'=>'prompt_injection']]],
    ['id'=>'manual.off_topic_three', 'title'=>'Три последовательных оффтопа', 'inputs'=>array_fill(0, 3, ['off_topic'=>true, 'client_intent'=>'off_topic'])],
    ['id'=>'manual.negative_twice', 'title'=>'Две негативные реплики подряд', 'inputs'=>array_fill(0, 2, ['sentiment'=>'negative'])],
    ['id'=>'manual.large_order', 'title'=>'Заявка на пороге escalation_amount_rub', 'inputs'=>[['slot_updates'=>['budget_range'=>'500000']]]],
    ['id'=>'manual.deferred_twice', 'title'=>'Повторная просьба отсрочки', 'inputs'=>array_fill(0, 2, ['client_intent'=>'ask_deferred', 'objections'=>['deferred']])],
    ['id'=>'manual.wants_human', 'title'=>'Явный запрос живого менеджера', 'inputs'=>[['wants_human'=>true, 'client_intent'=>'want_human']]],
    ['id'=>'manual.closed_topic', 'title'=>'Имя руководителя — закрытая тема', 'inputs'=>[['client_intent'=>'closed_topic', 'closed_topic'=>'management_names']]],
    ['id'=>'manual.no_repeat_filled_slot', 'title'=>'Заполненный город не спрашивается повторно', 'initial'=>['slots'=>['city'=>'Тула']], 'inputs'=>[[]]],
    ['id'=>'manual.force_close_14', 'title'=>'Четырнадцать ходов без завершения', 'inputs'=>array_fill(0, 14, [])],
    ['id'=>'manual.followup_20h', 'title'=>'Первое напоминание на границе 20 часов', 'scheduler_hours'=>[19.99, 20]],
    ['id'=>'manual.followup_40h', 'title'=>'Второе напоминание через 40 часов от исходной реплики', 'scheduler_hours'=>[20, 39.99, 40, 79.99, 80]],
];
