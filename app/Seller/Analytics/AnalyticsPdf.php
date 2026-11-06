<?php

namespace App\Seller\Analytics;

final class AnalyticsPdf
{
    public static function html(array $data, string $brandName, string $type): string
    {
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titles = ['weekly' => 'Сводка владельцу', 'dialogs' => 'Диалоги', 'topics' => 'Темы и вопросы', 'managers' => 'Воронка по менеджерам'];
        $rows = ['Диалоги' => $data['total'], 'Контакт получен' => $data['contact'], 'Передано менеджеру' => $data['handed'], 'Завершено' => $data['closed']];
        if ($type === 'dialogs') {
            $rows['Сообщения'] = $data['messageCount'];
            $rows['Бот завершил диалог'] = $data['botCompleted'];
        }
        if ($type === 'topics') {
            $rows['Диалоги с неизвестными вопросами'] = $data['unknownDialogs'];
            foreach (array_slice($data['topics'], 0, 10, true) as $topic => $count) {
                $rows['Тема: '.$topic] = $count;
            }
        }
        if ($type === 'managers') {
            foreach ($data['managers'] as $manager) {
                $rows['Менеджер: '.$manager['name'].' — назначено'] = $manager['assigned'];
                $rows['Менеджер: '.$manager['name'].' — завершено'] = $manager['closed'];
            }
        }
        $table = '';
        foreach ($rows as $label => $value) {
            $table .= '<tr><td>'.$escape($label).'</td><td>'.$escape($value).'</td></tr>';
        }
        $filters = [];
        foreach (['channel' => 'Канал', 'source' => 'Источник', 'manager' => 'Менеджер'] as $key => $label) {
            if ($data[$key] !== '') {
                $filters[] = $escape($label).': '.$escape($data[$key]);
            }
        }

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#13243d;font-size:11pt}h1{font-size:20pt;color:#14663c}h2{font-size:15pt}p{line-height:1.5}table{width:100%;border-collapse:collapse;margin-top:16px}td{padding:9px;border-bottom:1px solid #dfe5e2}td:last-child{text-align:right;font-weight:bold}.muted{color:#526478}</style></head><body><h1>'.$escape($brandName).'</h1><h2>'.$escape($titles[$type]).'</h2><p class="muted">Период: '.$escape($data['from']).' — '.$escape($data['to']).'</p><p class="muted">'.($filters ? implode(' · ', $filters) : 'Все каналы и источники').'</p><table>'.$table.'</table></body></html>';
    }
}
