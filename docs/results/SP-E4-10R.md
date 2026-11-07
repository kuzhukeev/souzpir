# SP-E4-10R — Песочница: тема и применённые приёмы

- Распознанная тема: `SandboxRuntime::turn` берёт `KnowledgeSnapshot::intent($client_intent)['title']` — то же поле, что редактируется на экране «База знаний → Темы» (`TopicsController::save`); если тема не подписана продавцом, используется тот же фолбэк, что и в `TopicsController::index` (humanized id). Если `client_intent` не распознан ни в одном интенте — «Не определена» (пусто).
- Сработавшие приёмы: вместо полного списка рекомендованных `goal->fewshot_tags` теперь показывается дедуплицированный список тегов реально отобранных примеров — `$snapshot->fewshot($goal->fewshotTags, $state->clientType(), 6, 0.7)`, тот же вызов, что строит промпт в `ResponderProjection::render`. Подписи — из `FewshotTags::LABELS`.
- Index.tsx: добавлена строка «Распознанная тема» (после «Предыдущий ответ бота»), «Теги приёмов» переименована в «Сработавшие приёмы» и использует новое поле `applied_techniques`.
- Проверено: tinker-прогон на фикстуре знаний (`ask_min_order` → тема «ask min order» без подписи, приёмы «Приветствие, Уточнение города») — реальные данные runtime, не выдумка.

## Тесты
- `php artisan test tests/Feature/Seller/Sandbox/*`: 6 passed (169 assertions) — регрессия.
- `tests/Browser/E4-10H/inspector.spec.ts` (расширен: тема/приёмы на двух ходах, пустое состояние «Не определена»/«Нет»): 1 passed, три темы и ширины 1440/1024/390, axe без critical/serious, скриншот `.local/screenshots/SP-E4-10R/inspector.png`.
- `tests/Browser/E4-10Q/sandbox.spec.ts`: 2 passed — регрессия.
- `check lint`, `check frontend` (tsc + build): успех.

## Остаток
- Нет — единственный пункт приёмки («тема и реально применённые приёмы без имитации») закрыт.

## Техдолг
- Нет.

## Принято по умолчанию
- Фолбэк названия темы при отсутствии явной подписи — тот же, что уже используется в `TopicsController` (humanized id), чтобы не плодить новую логику.
