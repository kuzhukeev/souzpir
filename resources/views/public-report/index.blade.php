<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $expired ? 'Ссылка недоступна' : 'Публичный отчёт · '.$brandName }}</title>
    <style>
        :root { color-scheme: light dark; font-family: Manrope, Arial, sans-serif; color: #172c26; background: #f7f7f3; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        header { background: white; color: #172c26; padding: 18px max(24px, calc((100vw - 1100px) / 2)); font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .brand-heading { display: flex; align-items: center; gap: 12px; min-width: 0; overflow-wrap: anywhere; } .brand-heading img { max-width: 120px; max-height: 48px; } .credit { color: #5d6c65; font-size: 14px; font-weight: 500; text-align: right; }
        main { max-width: 1100px; margin: 0 auto; padding: 48px 24px 80px; }
        h1 { font-size: clamp(28px, 4vw, 40px); line-height: 1.15; margin: 0 0 16px; }
        h2 { font-size: 20px; margin: 0 0 10px; }
        p { font-size: 15px; line-height: 1.55; }
        .muted { color: #5d6c65; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 36px; }
        .card { border: 1px solid #dce5dc; border-radius: 16px; background: white; padding: 22px; box-shadow: 0 4px 18px #1f3c2710; }
        .value { font-size: 28px; font-weight: 750; margin: 12px 0 0; }
        .site-layout { display: flex; flex-wrap: wrap; align-items: center; gap: 24px; margin-top: 16px; }
        .site-score { width: 132px; height: 132px; flex: none; border: 10px solid #dce5dc; border-radius: 50%; display: grid; place-items: center; text-align: center; }
        .site-score strong { font-size: 26px; } .site-score span { display: block; font-size: 14px; }
        .criteria { flex: 1 1 340px; list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
        .criteria li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; padding: 10px 12px; background: #f4f7f2; border-radius: 8px; font-size: 15px; }
        .action-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 0; list-style: none; }
        .action-grid li { background: #f4f7f2; border-radius: 10px; padding: 16px; font-size: 15px; line-height: 1.5; }
        details { border-top: 1px solid #dce5dc; padding: 12px 0; } summary { cursor: pointer; font-weight: 600; }
        .print-button[type="button"] { min-height: 44px; padding: 10px 16px; border: 0; border-radius: 8px; background: #245b3e; color: white; font-size: 15px; font-weight: 600; cursor: pointer; }
        .print-button[type="button"]:focus-visible { outline: 3px solid #246dcc; outline-offset: 3px; }
        @media (max-width: 760px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } main { padding-top: 32px; } }
        @media (max-width: 760px) { .action-grid { grid-template-columns: 1fr; } }
        @media print { .print-button { display: none; } :root { color: #172c26; background: white; } .card { break-inside: avoid; box-shadow: none; } }
        @media (prefers-color-scheme: dark) { :root { color: #e7f0e9; background: #16231c; } header { background: #243329; color: #e7f0e9; } .credit { color: #b4c7b9; } .card { background: #243329; border-color: #3f5948; } .muted { color: #b4c7b9; } .criteria li, .action-grid li { background: #1c2a21; } }
    </style>
</head>
<body>
<header><div class="brand-heading">@if (! $expired && isset($report['logo']) && is_string($report['logo']) && (str_starts_with($report['logo'], 'data:image/png;base64,') || str_starts_with($report['logo'], 'data:image/svg+xml;base64,')))<img src="{{ $report['logo'] }}" alt="Логотип {{ $brandName }}" >@endif @if (! $expired)<span>{{ $brandName }}</span>@endif</div> <span class="credit">Отчёт подготовлен <strong>EYON</strong></span></header>
<main>
    @if ($expired)
        <h1>Ссылка недоступна</h1>
        <p>Срок действия ссылки истёк или она была отозвана.</p>
    @else
        <p class="muted">Публичный отчёт · {{ $brandName }}</p>
        <h1>Видимость бренда в ИИ</h1>
        <p class="muted">Снимок опубликован {{ \Illuminate\Support\Carbon::parse($publishedAt)->format('d.m.Y') }}. Данные не обновляются после публикации.</p>
        @if ($report['allow_pdf'] ?? false)
            <button type="button" class="print-button" onclick="window.print()">Сохранить в PDF</button>
            <p class="muted print-button">В окне печати выберите сохранение в PDF.</p>
        @endif
        <div class="grid">
            @foreach ([
                ['Видимость', $report['visibility'], '%', 'visibility'],
                ['Доля голоса', $report['share_of_voice'], '%', 'share_of_voice'],
                ['Средняя позиция', $report['position'], '', 'position'],
                ['Ошибок о вас', $report['verified_errors'] ?? null, '', 'verified_errors'],
            ] as [$title, $value, $suffix, $metric])
                <section class="card"><h2>{{ $title }}</h2><p class="value">{{ $value === null ? 'Нет данных' : ($metric === 'verified_errors' ? (string) $value : number_format($value, 1, ',', ' ').$suffix) }}</p>
                    @php
                        $series = collect($report['history'] ?? [])->pluck($metric)->filter(fn ($v) => is_numeric($v))->values()->all();
                        $points = [];
                        if (count($series) >= 2) {
                            $min = min($series); $range = max($series) - $min ?: 1;
                            foreach ($series as $i => $v) { $points[] = round($i * 120 / (count($series) - 1), 2).','.round(36 - (($v - $min) / $range * 32), 2); }
                        }
                    @endphp
                    @if ($points)<svg role="img" aria-label="Динамика: {{ $title }}" viewBox="0 0 120 40" style="width:100%;height:40px;margin-top:8px"><polyline points="{{ implode(' ', $points) }}" fill="none" stroke="#16834b" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>@endif
                </section>
            @endforeach
        </div>
        <section class="card" style="margin-top: 24px"><h2>Главные выводы</h2>
            @if (($report['visibility'] ?? null) !== null || ($report['share_of_voice'] ?? null) !== null || ($report['position'] ?? null) !== null)
                <ol>
                    @if (($report['visibility'] ?? null) !== null)<li>Видимость бренда: {{ number_format($report['visibility'], 1, ',', ' ') }}%.</li>@endif
                    @if (($report['share_of_voice'] ?? null) !== null)<li>Доля голоса: {{ number_format($report['share_of_voice'], 1, ',', ' ') }}%.</li>@endif
                    @if (($report['position'] ?? null) !== null)<li>Средняя позиция: {{ number_format($report['position'], 1, ',', ' ') }}.</li>@endif
                </ol>
            @else<p class="muted">Проверенных выводов для этого снимка пока нет.</p>@endif
        </section>
        <section class="card" style="margin-top: 24px"><h2>Кого называют вместо вас</h2>@if (count($report['competitors'] ?? []))<p>Чаще всего среди других компаний упоминается {{ $report['competitors'][0]['name'] }}: {{ $report['competitors'][0]['answers'] }} ответов.</p><ol>@foreach ($report['competitors'] as $company)<li>{{ $company['name'] }} · упоминаний: {{ $company['answers'] }}</li>@endforeach</ol>@else<p class="muted">Нет подтверждённых упоминаний компаний.</p>@endif</section>
        <section class="card" style="margin-top: 24px"><h2>Что о вас говорят</h2>@if (array_key_exists('reviewed_facts', $report))<p>Утверждений в снимке: {{ $report['reviewed_facts'] }}; подтверждено: {{ $report['confirmed_facts'] }}.</p>@else<p class="muted">Счётчики проверенных утверждений в этой публикации недоступны.</p>@endif</section>
        <section class="card" style="margin-top: 24px"><h2>Ваш сайт</h2>
            @php($site = $report['site_readiness'] ?? null)
            @if ($site !== null)
                <div class="site-layout"><div class="site-score" aria-label="Оценка сайта на момент публикации"><div><strong>{{ is_array($site) ? $site['lower'].'–'.$site['upper'] : $site }}</strong><span>из 100</span></div></div>
                @if (count($report['site_criteria'] ?? []))<ul class="criteria">@foreach ($report['site_criteria'] as $criterion)<li><span>{{ $criterion['title'] }}</span><strong>{{ ['pass' => 'Есть', 'fail' => 'Нет', 'partial' => 'Частично', 'unknown' => 'Неизвестно'][$criterion['status']] ?? 'Неизвестно' }}</strong></li>@endforeach</ul>@endif</div>
                <p class="muted">Оценка на момент публикации; диапазон отражает неопределённость.</p>
            @else<p class="muted">Оценка сайта в этой публикации недоступна.</p>@if (count($report['site_criteria'] ?? []))<ul class="criteria">@foreach ($report['site_criteria'] as $criterion)<li><span>{{ $criterion['title'] }}</span><strong>{{ ['pass' => 'Есть', 'fail' => 'Нет', 'partial' => 'Частично', 'unknown' => 'Неизвестно'][$criterion['status']] ?? 'Неизвестно' }}</strong></li>@endforeach</ul>@endif @endif
        </section>
        <section class="card" style="margin-top: 24px"><h2>Три действия</h2>@if (count($report['actions'] ?? []))<ol class="action-grid">@foreach ($report['actions'] as $action)<li><strong>{{ $action }}</strong>@php($details = $report['action_details'][$loop->index] ?? null) @if ($details && (!empty($details['priority']) || !empty($details['steps']))) @if (!empty($details['priority']))<p>Приоритет: {{ $details['priority'] }}</p>@endif @if (!empty($details['steps']))<ol>@foreach ($details['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>@endif @else<p class="muted">Приоритет и шаги не опубликованы.</p>@endif</li>@endforeach</ol>@else<p class="muted">Подтверждённых действий пока нет.</p>@endif</section>
        <section class="card" style="margin-top: 24px"><h2>История изменений</h2>@if (count($report['history'] ?? []))<p class="muted">Подтверждённые замеры с одинаковым набором вопросов, моделей и повторов на момент публикации.</p><div style="overflow-x:auto"><table style="width:100%;min-width:560px;text-align:left;font-size:14px"><thead><tr><th>Дата</th><th>Видимость, %</th><th>Покрытие, %</th><th>Доля голоса, %</th><th>Позиция</th></tr></thead><tbody>@foreach ($report['history'] as $point)<tr><td>{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d.m.Y') }}</td>@foreach (['visibility','coverage','share_of_voice','position'] as $metric)<td>{{ ($point[$metric] ?? null) === null ? '—' : number_format($point[$metric], 1, ',', ' ') }}</td>@endforeach</tr>@endforeach</tbody></table></div>@else<p class="muted">Сопоставимая история не включена в этот снимок.</p>@endif</section>
        <section class="card" style="margin-top: 24px"><h2>Методика</h2><details><summary>Вопросы</summary><p>В снимке учтено {{ $report['questions'] }} вопросов.</p></details>
            <details><summary>Ответы моделей</summary><p>Пропущенные и непроверенные ответы не считаются отрицательными.</p></details>
            <details><summary>Видимость</summary><p>Показатель рассчитан по доступным проверенным ответам.</p></details>
            <details><summary>Компании</summary><p>Показаны подтверждённые упоминания компаний в опубликованном снимке.</p></details>
            <details><summary>Ограничения</summary><p>Данные не обновляются после публикации.</p></details></section>
    @endif
<p class="muted" style="margin-top: 32px">Отчёт подготовлен EYON</p>
</main>
</body>
</html>
