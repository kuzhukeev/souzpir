<?php

namespace App\Seller\Analytics;

final class AnalyticsPdf
{
    public static function html(array $data, string $brandName, string $type): string
    {
        $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titles = ['weekly' => 'Сводка владельцу', 'dialogs' => 'Диалоги', 'topics' => 'Темы и вопросы', 'managers' => 'Воронка по менеджерам'];
        $rows = ['Диалоги' => $data['total'], 'Контакт получен' => $data['contact'], 'Передано менеджеру' => $data['handed'], 'Завершено' => $data['closed']];
        if ($type === 'weekly') {
            $rows['Сообщения'] = $data['messageCount'];
            $rows['Без передачи менеджеру'] = $data['botCompleted'];
        }
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
                $rows['Менеджер: '.$manager['name'].' — принято'] = $manager['taken'];
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
                $value = $data[$key];
                if ($key === 'manager') {
                    $match = collect($data['managerOptions'] ?? [])->firstWhere('id', $value);
                    $value = $match['name'] ?? 'Не найден';
                }
                $filters[] = $escape($label).': '.$escape($value);
            }
        }

        $series = array_slice($data['daily'] ?? [], -14, null, true);
        $chart = '';
        if ($series !== []) {
            $maximum = max(1, ...array_values($series));
            $chart = '<h3>Динамика диалогов за последние дни</h3><table class="chart">';
            foreach ($series as $date => $count) {
                $width = (int) round(max(0, (float) $count) / $maximum * 100);
                $chart .= '<tr><td>'.$escape($date).'</td><td><div class="track"><div class="bar" style="width:'.$width.'%"></div></div></td><td>'.$escape($count).'</td></tr>';
            }
            $chart .= '</table>';
        } else {
            $chart = '<p class="muted">Данных о динамике за период пока нет.</p>';
        }

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#13243d;font-size:11pt}h1{font-size:20pt;color:#14663c}h2{font-size:15pt}p{line-height:1.5}table{width:100%;border-collapse:collapse;margin-top:16px}td{padding:9px;border-bottom:1px solid #dfe5e2}td:last-child{text-align:right;font-weight:bold}.muted{color:#526478}.chart{font-size:9pt;margin-top:4px}.chart td{padding:4px;border:0}.chart td:first-child{width:90px}.chart td:last-child{width:30px;text-align:right}.track{background:#e7eeeb;height:9px}.bar{height:9px;background:#218351}</style></head><body><h1>'.$escape($brandName).'</h1><h2>'.$escape($titles[$type]).'</h2><p class="muted">Период: '.$escape($data['from']).' — '.$escape($data['to']).'</p><p class="muted">'.($filters ? implode(' · ', $filters) : 'Все каналы и источники').'</p><table>'.$table.'</table>'.$chart.'</body></html>';
    }
}
