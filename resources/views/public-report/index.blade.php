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
        header { background: #245b3e; color: white; padding: 24px max(24px, calc((100vw - 1100px) / 2)); font-size: 16px; font-weight: 700; }
        main { max-width: 1100px; margin: 0 auto; padding: 48px 24px 80px; }
        h1 { font-size: clamp(28px, 4vw, 40px); line-height: 1.15; margin: 0 0 16px; }
        h2 { font-size: 20px; margin: 0 0 10px; }
        p { font-size: 15px; line-height: 1.55; }
        .muted { color: #5d6c65; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 36px; }
        .card { border: 1px solid #dce5dc; border-radius: 16px; background: white; padding: 22px; box-shadow: 0 4px 18px #1f3c2710; }
        .value { font-size: 28px; font-weight: 750; margin: 12px 0 0; }
        @media (max-width: 760px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } main { padding-top: 32px; } }
        @media (max-width: 420px) { .grid { grid-template-columns: 1fr; } }
        @media (prefers-color-scheme: dark) { :root { color: #e7f0e9; background: #16231c; } .card { background: #243329; border-color: #3f5948; } .muted { color: #b4c7b9; } }
    </style>
</head>
<body>
<header>Союз Пир · Видение</header>
<main>
    @if ($expired)
        <h1>Ссылка недоступна</h1>
        <p>Срок действия ссылки истёк или она была отозвана.</p>
    @else
        <p class="muted">Публичный отчёт · {{ $brandName }}</p>
        <h1>Видимость бренда в ИИ</h1>
        <p class="muted">Снимок опубликован {{ \Illuminate\Support\Carbon::parse($publishedAt)->format('d.m.Y') }}. Данные не обновляются после публикации.</p>
        <div class="grid">
            @foreach ([
                ['Видимость', $report['visibility'], '%'],
                ['Покрытие', $report['coverage'], '%'],
                ['Доля голоса', $report['share_of_voice'], '%'],
                ['Средняя позиция', $report['position'], ''],
            ] as [$title, $value, $suffix])
                <section class="card"><h2>{{ $title }}</h2><p class="value">{{ $value === null ? '—' : number_format($value, 1, ',', ' ').$suffix }}</p></section>
            @endforeach
        </div>
        <section class="card" style="margin-top: 24px"><h2>Главные выводы</h2><ul><li>Видимость: {{ $report['visibility'] === null ? 'недостаточно данных' : number_format($report['visibility'], 1, ',', ' ').' %' }}.</li><li>Покрытие ответов: {{ $report['coverage'] === null ? 'недостаточно данных' : number_format($report['coverage'], 1, ',', ' ').' %' }}.</li><li>В снимке {{ $report['questions'] }} вопросов.</li></ul></section>
        <section class="card" style="margin-top: 24px"><h2>Кого называют вместо вас</h2><p class="muted">Детализация компаний в этой публикации недоступна.</p></section>
        <section class="card" style="margin-top: 24px"><h2>Что о вас говорят</h2><p class="muted">Счётчики проверенных утверждений в этой публикации недоступны.</p></section>
        <section class="card" style="margin-top: 24px"><h2>Ваш сайт</h2><p class="muted">Оценка сайта в этой публикации недоступна.</p></section>
        <section class="card" style="margin-top: 24px"><h2>Три действия</h2><p class="muted">Подтверждённые действия в этой публикации недоступны.</p></section>
        <section class="card" style="margin-top: 24px"><h2>Методика</h2><details><summary>Как рассчитаны показатели</summary><p>В снимке учтено {{ $report['questions'] }} вопросов. Пропущенные и непроверенные ответы не считаются отрицательными.</p></details></section>
    @endif
</main>
</body>
</html>
