<?php

$menu = require base_path('config/navigation.php');
$labels = ['modules' => 'Модули', 'account' => 'Кабинет', 'soon' => 'Скоро'];
$labels[$menu['home']['id']] = $menu['home']['label'];
foreach ($menu['modules'] as $group) {
    $labels[$group['id']] = $group['label'];
    foreach ($group['children'] as $item) {
        $labels[$item['id']] = $item['label'];
    }
}
foreach ($menu['account'] as $item) {
    $labels[$item['id']] = $item['label'];
}

return $labels;
