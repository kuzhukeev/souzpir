Модель: Sonnet 5
# SP-E6-03W — Реакции: сквозные сценарии отказа

- Отказ («Не пишите мне», goal `opt_out`): добавлен в разрешённый список исходов `FirstTurnTextFinalizer` и `FollowupFinalizer` (совместимое добавление — `do_not_disturb`/флаг и stage `lost` уже считались Policy, не хватало только пропуска через финализатор); исключён из приветствия, как и `handoff`. Проверено: `ReactionRuntimeE603VTest` — первый ход с `opt_out` теперь завершается (было 503), флаг `do_not_disturb=true`, стадия `lost`, текст из `opt_out_ack`.
- Два негативных сообщения подряд: сценарий уже поддерживался Policy (`negative_streak_escalate`), но не был проверен сквозь протокол. Новый тест `NegativeStreakEscalationE603WTest` гоняет два хода через `/api/v1/turn` (fake-драйвер) и подтверждает `goal.kind=handoff`, `conversation.stage=handoff`, `mode=manager`.
- Убрана устаревшая плашка «в тестовом runtime отказ в первом ходе пока недоступен» в карточке «Вне темы и негатив» — больше не соответствует действительности после фикса `opt_out`.
- D-5.6 «Коротких ответов N»: порог переключения фразы `decline_off_topic`→`decline_off_topic_second` зашит в `app/Seller/Engine/Policy.php` как `2`. Начал выносить его в настраиваемый `policy.limits`, но `app/Seller/Engine/Policy.php` не входит в scope карточки (`app/Seller/Settings/Policy/`, `app/Seller/Runtime/`, `app/ProtectedOperations/`) — откатил это изменение и связанные тесты, чтобы не трогать чужой файл. Перенесено в «Нужно от интегратора».
- Проверено по макету: карточки реакций (`ReactionExamples.tsx`) без изменений — расположение/действия уже сверены в SP-E6-03V; текстовое расхождение «списки фраз, не одна строка» зафиксировано в journal как намеренное отступление от макета.
- Тесты: PHPUnit — `ReactionRuntimeE603VTest`, `NegativeStreakEscalationE603WTest` (новый) — зелёные; полный набор `tests/Feature/Seller/Runtime`, `tests/Feature/Seller/Channels/Api`, `tests/Unit/Seller/Engine` (142), `tests/Feature/Seller/Settings/Policy` — зелёные. `npm run lint/typecheck/test/build` — зелёные.

## Остаток
- Поле «Коротких ответов N» (D-5.6, дополнение владельца от 05.11.2026) не реализовано: требует правки `app/Seller/Engine/Policy.php` вне scope карточки.
- Полная визуальная перекладка карточек D-5.6 под 2×2-сетку мокета не делалась (решение SP-E6-03V уже закрепило расхождение «списки фраз»); если понадобится точное визуальное соответствие — отдельным пунктом полировки UI.

## Техдолг
- Нет новых рисков сверх уже описанных в SP-E6-03V.

## Принято по умолчанию
- `opt_out` не требует модельного guard (не входит в MODEL_GOALS) — поведение остальных kind не менялось.

## Нужно от интегратора
- `app/Seller/Engine/Policy.php:294-295` — порог `decline_off_topic_second` зашит как `$streak >= 2`. Нужно вынести в `$this->limit('decline_off_topic_second_after', 2)` (как остальные лимиты) и дать scope на этот файл + `app/Seller/Settings/Policy/OffTopicController.php` (добавить поле `decline_off_topic_second_after` в валидацию и сохранение) + `resources/js/pages/Seller/Settings/Policy/Index.tsx` (поле в форме «Вне темы и негатив»), чтобы закрыть «Коротких ответов N» из D-5.6.
