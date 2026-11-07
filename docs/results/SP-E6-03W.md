Модель: Sonnet 5
# SP-E6-03W — Реакции: сквозные сценарии отказа

- Отказ («Не пишите мне», goal `opt_out`): добавлен в разрешённый список исходов `FirstTurnTextFinalizer` и `FollowupFinalizer` (совместимое добавление — `do_not_disturb`/флаг и stage `lost` уже считались Policy, не хватало только пропуска через финализатор); исключён из приветствия, как и `handoff`. Проверено: `ReactionRuntimeE603VTest` — первый ход с `opt_out` теперь завершается (было 503), флаг `do_not_disturb=true`, стадия `lost`, текст из `opt_out_ack`.
- Два негативных сообщения подряд: сценарий уже поддерживался Policy (`negative_streak_escalate`), но не был проверен сквозь протокол. Новый тест `NegativeStreakEscalationE603WTest` гоняет два хода через `/api/v1/turn` (fake-драйвер) и подтверждает `goal.kind=handoff`, `conversation.stage=handoff`, `mode=manager`.
- D-5.6 «Коротких ответов N»: порог переключения фразы `decline_off_topic`→`decline_off_topic_second` был зашит как `2`; вынесен в `policy.limits.decline_off_topic_second_after` (дефолт 2), поле добавлено в форму «Вне темы и негатив» рядом с существующими лимитами. Убрана устаревшая плашка «отказ в первом ходе недоступен».
- Проверено по макету: карточки реакций (`ReactionExamples.tsx`) без изменений — расположение/действия уже сверены в SP-E6-03V; текстовое расхождение «списки фраз, не одна строка» зафиксировано в journal как намеренное отступление от макета.
- Тесты: PHPUnit — `ReactionRuntimeE603VTest`, `NegativeStreakEscalationE603WTest` (новый), `OffTopicTest`, `OffTopicPolicyE603CTest` (новый кейс), `PolicyTest` — все зелёные; полный набор `tests/Feature/Seller/Runtime`, `tests/Feature/Seller/Channels/Api`, `tests/Unit/Seller/Engine` (142), `tests/Feature/Seller/Settings/Policy` — зелёные. `npm run lint/typecheck/test/build` — зелёные.

## Остаток
- Полная визуальная перекладка карточек D-5.6 под 2×2-сетку мокета не делалась (решение SP-E6-03V уже закрепило расхождение «списки фраз»); если понадобится точное визуальное соответствие — отдельным пунктом полировки UI.
- Новый скриншот для добавленного поля «Коротких ответов до смены фразы» не снят: поле живёт внутри `CabinetLayout`-страницы, которую нельзя дёшево изолировать фикстурой как `ReactionExamples`; функционально покрыто `OffTopicTest`.

## Техдолг
- Нет новых рисков сверх уже описанных в SP-E6-03V.

## Принято по умолчанию
- `decline_off_topic_second_after` по умолчанию = 2 (текущее поведение), валидация 1..10, как у соседних лимитов.
- `opt_out` не требует модельного guard (не входит в MODEL_GOALS) — поведение остальных kind не менялось.

## Нужно от интегратора
- Нет.
