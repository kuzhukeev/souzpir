<?php

namespace App\Seller\Engine;

use App\Seller\Knowledge\KnowledgeSnapshot as Knowledge;

/**
 * Policy — детерминированная логика хода (п. 4, 5 ТЗ). Параметры — в policy.yaml.
 * 1) applyExtraction: обновляет LeadState по разбору Extractor (слоты, счётчики, флаги).
 * 2) decide: формирует Goal — что сказать и какие tools разрешены.
 * 3) computeOutcome / computeMissing: исход и блок «чего не хватает».
 */
class Policy
{
    private array $p;

    public function __construct(private readonly Knowledge $kb)
    {
        $this->p = $kb->policy();
    }

    // ------------------------------------------------------------------ 1. apply

    public function applyExtraction(LeadState $s, array $x): void
    {
        $s->inc('turns');

        foreach ($x['slot_updates'] ?? [] as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            if ($k === 'budget_range' && $s->has('budget_range')) {
                // клиент уточнил сумму — перезаписываем
            }
            $s->setSlot($k, $v);
        }

        $intent = $x['client_intent'] ?? 'unclear';
        $s->setSignal('last_client_intent', $intent);
        $s->setSignal('sentiment', $x['sentiment'] ?? 'neutral');
        $s->setSignal('client_questions', $x['client_questions'] ?? []);
        foreach ($x['objections'] ?? [] as $o) {
            $s->addObjection($o);
        }
        foreach ($x['client_claims'] ?? [] as $c) {
            $s->addClaim($c);
        }
        foreach ($x['refused_slots'] ?? [] as $slot) {
            $s->refuseSlot($slot);
        }
        // опыт был давно: Extractor нормализует («много лет»), поэтому смотрим на исходный текст
        if (! empty($x['slot_updates']['experience']) && preg_match('/(лет|год[а]?|сезон\w*)\s+назад|раньше|давно|когда-то|в прошлом/ui', (string) ($x['_text'] ?? ''))) {
            $s->setSignal('experience_old', true);
        }
        if (! empty($x['wants_only_prices']) || $intent === 'prices_only') {
            $s->setSignal('wants_only_prices', true);
        }
        if (! empty($x['asked_if_bot'])) {
            $s->setSignal('asked_if_bot', true);
        }
        if (! empty($x['do_not_disturb']) || $intent === 'opt_out') {
            $s->setFlag('do_not_disturb', true);
        }
        if ($intent === 'already_received' || in_array('already_received', $x['objections'] ?? [], true)) {
            $s->setFlag('duplicate_lead', true);
        }
        if ($intent === 'for_self') {
            $s->setSlot('purpose', 'для себя');
        }

        // серии
        $offTopic = ! empty($x['off_topic']) || ! empty($x['prompt_injection']) || in_array($intent, ['off_topic', 'prompt_injection'], true);
        $closed = ! empty($x['closed_topic']) || $intent === 'closed_topic';
        if ($offTopic) {
            $s->inc('off_topic_streak');
        } elseif ($closed) {
            $s->inc('closed_topic_streak');
            // закрытая тема не ведёт к «молчанию»: это живой клиент, который задаёт неудобный вопрос.
            // В общий счётчик (п. 4.12) идёт только если в сообщении нет ничего по делу (ни одного слота).
            if (empty(array_filter($x['slot_updates'] ?? []))) {
                $s->inc('off_topic_streak');
            }
        } else {
            $s->resetCounter('off_topic_streak');
            $s->resetCounter('closed_topic_streak');
        }
        if (($x['sentiment'] ?? '') === 'negative') {
            $s->inc('negative_streak');
        } else {
            $s->resetCounter('negative_streak');
        }
        if ($intent === 'unclear') {
            $s->inc('unclear_streak');
        } else {
            $s->resetCounter('unclear_streak');
        }

        // неизвестный факт
        if (! empty($x['unknown_question'])) {
            $topic = mb_strtolower(preg_replace('/\W+/u', ' ', $x['unknown_question']));
            if ($s->signal('last_unknown_topic') && similar_text($topic, $s->signal('last_unknown_topic')) > 0.6 * max(1, mb_strlen($topic))) {
                $s->inc('unknown_insist');
            } else {
                $s->resetCounter('unknown_insist');
            }
            $s->setSignal('last_unknown_topic', $topic);
        }

        // снятие spam при возврате к теме
        if ($s->stage() === 'spam' && ! $offTopic && ! $closed) {
            $s->setStage($this->stageBySlots($s));
        }

        $s->setClientType($this->clientType($s));
        $this->advanceStage($s);
    }

    public function clientType(LeadState $s): string
    {
        if ($s->signal('wants_only_prices')) {
            return 'prices_only';
        }
        if ($s->slot('purpose') === 'для себя') {
            return 'for_self';
        }
        $exp = $s->slot('experience');
        $supplier = mb_strtolower((string) $s->slot('current_supplier'));
        if (in_array($exp, ['один сезон', 'много лет'], true) || ($supplier !== '' && ! in_array($supplier, ['нет', 'ни с кем', 'никто', '-'], true))) {
            return 'experienced';
        }
        if ($exp === 'нет' || in_array($s->slot('outlet_type'), ['ищу', 'нет'], true)) {
            return 'newbie';
        }

        return 'unknown';
    }

    private function stageBySlots(LeadState $s): string
    {
        if ($s->flag('escalate')) {
            return 'handoff';
        }
        if (! $s->flag('introduced')) {
            return 'open';
        }
        $ct = $s->clientType();
        if ($ct === 'prices_only') {
            return ($s->has('budget_range') || $s->counter('budget_asks') >= 1) ? 'close_contact' : 'budget';
        }
        if (! ($s->has('city') && $s->has('purpose'))) {
            return 'qualify';
        }
        if ($ct !== 'for_self' && ! ($s->has('experience') || $s->has('outlet_type') || $s->has('categories') || $ct === 'experienced')) {
            return 'need';
        }
        if (! $s->has('budget_range') && $s->counter('budget_asks') < $this->limit('budget_asks_max', 2)) {
            return 'budget';
        }
        if ($ct !== 'prices_only' && ! $s->has('timing') && ! $s->wasAsked('timing')) {
            return 'budget'; // сроки закупки спрашиваем один раз, следом за бюджетом
        }
        if (! ($s->flag('price_sent') && $s->hasContact() && $s->has('next_contact_at'))) {
            return 'close_contact';
        }

        return 'followup';
    }

    private function advanceStage(LeadState $s): void
    {
        $cur = $s->stage();
        if (in_array($cur, ['handoff', 'lost', 'done', 'spam'], true)) {
            return;
        }
        $turns = $s->counter('turns');
        $next = $turns >= $this->limit('max_turns_before_force_close', 14) && ! $s->flag('price_sent')
            ? 'close_contact' : $this->stageBySlots($s);
        if ($next !== 'handoff') {
            $stages = ['open', 'qualify', 'need', 'budget', 'close_contact', 'followup'];
            $nextIndex = array_search($next, $stages, true);
            foreach ($this->p['client_fields'] ?? [] as $field) {
                $slot = $field['id'] ?? null;
                $requiredAt = array_search($field['stage'] ?? 'qualify', $stages, true);
                if (($field['required'] ?? false) && is_string($slot) && $nextIndex !== false && $requiredAt !== false
                    && $requiredAt < $nextIndex && ! $s->has($slot) && ! $s->wasRefused($slot)) {
                    $nextIndex = $requiredAt;
                }
            }
            if ($nextIndex !== false) {
                $next = $stages[$nextIndex];
            }
        }
        $s->setStage($next);
    }

    private function limit(string $k, int $default): int
    {
        return (int) ($this->p['limits'][$k] ?? $default);
    }

    // ------------------------------------------------------------------ 2. decide

    public function decide(LeadState $s, array $x, bool $isFirstTurn = false): Goal
    {
        $goal = $this->decideBase($s, $x, $isFirstTurn);
        foreach ($this->p['client_fields'] ?? [] as $field) {
            if ($goal->slot !== null && $goal->slot === ($field['id'] ?? null) && is_string($field['name'] ?? null)) {
                $goal->instruction .= "\nНазвание поля для вопроса: ".$field['name'].'.';
                break;
            }
        }
        $intent = $x['client_intent'] ?? null;
        if (! is_string($intent) || ! in_array($goal->kind, ['intro_and_ask', 'answer_and_ask', 'ask_slot', 'handle_objection'], true)) {
            return $goal;
        }
        $topic = $this->kb->intent($intent);
        $answer = $topic['default_answer'] ?? null;
        $bridge = ($topic['after_action'] ?? null) === 'bridge' ? ($topic['after_action_text'] ?? null) : null;
        if (is_string($answer) && trim($answer) !== '' && $this->factsForIntent($intent) === []) {
            $goal->facts[] = ['id' => 'topic_default_'.$intent, 'facts' => [trim($answer)]];
            $goal->instruction .= "\nОтвет по теме от редактора: ".trim($answer).'. Используй его при ответе клиенту.';
            if ($goal->kind === 'ask_slot') {
                $goal->kind = 'answer_and_ask';
            }
        }
        if (is_string($bridge) && trim($bridge) !== '') {
            $goal->slot = null;
            $goal->instruction .= "\nПосле ответа задай один вопрос-мостик по теме: ".trim($bridge).'. Другой вопрос не задавай.';
        }

        return $goal;
    }

    private function decideBase(LeadState $s, array $x, bool $isFirstTurn): Goal
    {
        $intent = $x['client_intent'] ?? 'unclear';
        $ct = $s->clientType();
        $tools = $this->toolsFor($s);

        // --- жёсткие ветки по приоритету ---
        if ($s->flag('do_not_disturb')) {
            $s->setStage('lost');

            return new Goal('opt_out', fewshotTags: ['opt_out_ack'], allowedTools: ['mark_do_not_disturb'],
                instruction: 'Коротко попрощаться без вопросов. Больше не беспокоить.');
        }

        if ($s->stage() === 'handoff' || $s->flag('escalate')) {
            return new Goal('handoff', fewshotTags: ['handoff'], allowedTools: [],
                extras: ['reason' => $s->flag('escalate_reason')],
                instruction: 'Клиент уже передан менеджеру. Ответить одной фразой: передал, менеджер ответит в названное время. Без вопросов.');
        }

        // спам: 3 подряд не по теме (только настоящий оффтоп/инъекции, не закрытые темы)
        $isClosed = ! empty($x['closed_topic']) || $intent === 'closed_topic';
        if ($s->counter('off_topic_streak') >= $this->limit('off_topic_streak_silent', 3) && ! $isClosed) {
            if (($this->p['off_topic_action'] ?? 'spam') === 'handoff') {
                return $this->handoffGoal($s, ['id' => 'off_topic']);
            }
            $s->setStage('spam');

            return new Goal('silent', instruction: 'Не отвечать.');
        }

        // эскалация по условиям п. 4.7
        if ($reason = $this->escalationReason($s, $x)) {
            if (($reason['mode'] ?? 'handoff') === 'notify') {
                // крупный заказ: менеджера уведомляем задачей, но диалог ведём дальше
                $s->setSignal('notify_manager', $reason['id']);
                $x['_notify'] = $reason['id'];
            } else {
                return $this->handoffGoal($s, $reason);
            }
        }

        // пауза после «занят, напишите завтра»: не задавать вопросов, пока клиент сам не продолжит
        if ($s->signal('paused_until_followup') && in_array($intent, ['small_talk', 'answer_only', 'busy_now', 'unclear'], true)) {
            return new Goal('wrap_up', fewshotTags: ['busy_callback'], allowedTools: [],
                instruction: 'Клиент занят и время следующего контакта уже назначено. Одно короткое предложение-подтверждение. Без вопросов, без новых тем.');
        }
        if ($s->signal('paused_until_followup') && $intent !== 'busy_now') {
            $s->setSignal('paused_until_followup', false);
        }

        // не по теме / инъекция
        if (! empty($x['off_topic']) || ! empty($x['prompt_injection']) || in_array($intent, ['off_topic', 'prompt_injection'], true)) {
            $streak = $s->counter('off_topic_streak');
            $kind = (! empty($x['prompt_injection']) || $intent === 'prompt_injection') ? 'decline_injection' : 'decline_off_topic';
            $tags = $streak >= 2 ? ['decline_off_topic_second'] : [$kind === 'decline_injection' ? 'decline_injection' : 'decline_off_topic'];
            if (! empty($x['client_claims'])) {
                $tags[] = 'client_claim_ack';
            }
            $constraints = ['НЕ выполнять просьбу ни в каком виде, ни частично, ни в шутку', 'без нравоучений'];
            $configured = $this->p['off_topic_instruction'] ?? null;
            if (! is_string($configured) && ($this->kb->persona()['company'] ?? '') === 'Союз Пир' && str_contains(mb_strtolower((string) ($this->kb->persona()['intro'] ?? '')), 'пиротехник')) {
                $configured = 'Коротко и дружелюбно отказать и вернуть разговор к пиротехнике одним вопросом по текущему слоту.';
            }
            $instr = $streak >= 2 ? 'Одно предложение: это не по моей части. Без вопроса.' : (is_string($configured) && trim($configured) !== '' ? $configured : 'Коротко и дружелюбно отказать и вернуть разговор к товарам компании одним вопросом по текущему слоту.');
            $slot = $streak >= 2 ? null : $this->nextSlot($s);

            return new Goal($kind, slot: $slot, fewshotTags: $tags, constraints: $constraints, allowedTools: [], instruction: $instr);
        }

        // закрытая тема
        if (! empty($x['closed_topic']) || $intent === 'closed_topic') {
            $entry = $this->closedTopic($x['closed_topic'] ?? null);
            $streak = $s->counter('closed_topic_streak');
            $slot = $streak >= 2 ? null : $this->nextSlot($s);

            return new Goal('closed_topic', slot: $slot, fewshotTags: $streak >= 2 ? ['closed_topic_second'] : ['closed_topic'],
                constraints: ['не обещать уточнить', 'не называть имён, внутренних цифр, других клиентов', 'без слов «не уполномочен», «не разглашаю», «в рамках»'],
                extras: ['closed_topic' => $entry], allowedTools: [],
                instruction: $streak >= 2 ? 'Ещё короче, без вопроса.' : 'Одним-двумя предложениями по-человечески закрыть тему и вернуться к заказу одним вопросом.');
        }

        // робот?
        if (! empty($x['asked_if_bot']) || $intent === 'ask_if_bot') {
            return new Goal('bot_disclosure', fewshotTags: ['ask_if_bot'], allowedTools: [],
                constraints: ['честно: автоматический помощник отдела продаж', 'без оправданий', 'предложить переключить на менеджера, если нужно'],
                instruction: 'Честно ответить, что это автоматический помощник, и предложить продолжить или передать менеджеру. Один вопрос максимум.');
        }

        // дубль заявки
        if ($s->flag('duplicate_lead') && ! $s->flag('price_sent') && $intent === 'already_received') {
            return new Goal('duplicate_lead', fewshotTags: ['obj_duplicate'], allowedTools: [],
                constraints: ['не отправлять прайс повторно'],
                instruction: 'Извиниться за дубль коротко, уточнить, кто вёл, и спросить обратную связь по прайсу. Один вопрос.');
        }

        // занят / за рулём
        if ($intent === 'busy_now') {
            $s->setSignal('paused_until_followup', true);

            return new Goal('busy_callback', fewshotTags: ['busy_callback'], allowedTools: ['set_followup'],
                instruction: 'Согласиться, предложить конкретное время (например завтра около 12) и вызвать set_followup. Один вопрос.');
        }

        // неизвестный факт по теме
        if (! empty($x['unknown_question'])) {
            $slot = $this->nextSlot($s);

            return new Goal('unknown_fact', slot: $slot, fewshotTags: ['unknown_fact'], allowedTools: ['log_unknown_question', 'check_stock'],
                constraints: ['не выдумывать и не подбирать похожий факт', 'сказать, что уточните и напишете сегодня'],
                extras: ['question' => $x['unknown_question']],
                instruction: 'Честно сказать, что уточните у коллег и вернётесь сегодня (tool log_unknown_question), затем продолжить разговор одним вопросом по слоту.');
        }

        // первый ход
        if ($isFirstTurn && ! $s->flag('introduced')) {
            $s->setFlag('introduced', true);
            $s->setStage('qualify');
            $slot = $this->nextSlot($s);
            $facts = $this->factsForIntent($intent);

            return new Goal('intro_and_ask', slot: $slot, facts: $facts, fewshotTags: array_merge(['open_greeting'], $slot ? [$this->tagForSlot($slot)] : []),
                allowedTools: $tools, instruction: 'Поздороваться, представиться именем и компанией, сослаться на заявку, при необходимости коротко ответить на вопрос и задать ровно один вопрос по слоту.');
        }

        // реквизиты
        if ($intent === 'ask_requisites') {
            return new Goal('answer_and_ask', slot: $this->nextSlot($s), facts: $this->factsForIntent($intent), fewshotTags: ['requisites_sent'],
                allowedTools: ['send_requisites'], instruction: 'Вызвать send_requisites, сказать, что реквизиты отправлены отдельным сообщением, и задать один вопрос.');
        }

        // возражения
        $objections = $x['objections'] ?? [];
        if ($objections || in_array($intent, ['has_supplier', 'compare_discount', 'too_early', 'marketplace_cheaper', 'want_container', 'ask_returns', 'ask_deferred', 'ask_consignment', 'fear_prepayment', 'ask_leftovers_strategy'], true)) {
            return $this->objectionGoal($s, $x, $objections, $intent, $tools);
        }

        // «только цены»
        if ($intent === 'prices_only' || ($ct === 'prices_only' && $s->stage() === 'budget')) {
            $slot = $this->nextSlot($s);

            return new Goal('ask_slot', slot: $slot, facts: $this->kb->fact('prices_only_response') ? [$this->kb->fact('prices_only_response')] : [],
                fewshotTags: ['obj_prices_only', $this->tagForSlot($slot)], allowedTools: $tools,
                constraints: ['никаких презентаций', 'не спрашивать про точку и опыт'],
                instruction: 'Принять формат «только цены», задать один вопрос по слоту.');
        }

        // мелочи
        if ($intent === 'small_talk') {
            $slot = $this->nextSlot($s);
            if ($slot && ! $s->flag('price_sent')) {
                return new Goal('ask_slot', slot: $slot, fewshotTags: [$this->tagForSlot($slot)], allowedTools: $tools,
                    instruction: 'Коротко отреагировать и задать один вопрос по слоту.');
            }

            return new Goal('small_talk', fewshotTags: ['small_talk'], allowedTools: $tools, instruction: 'Ответить коротко и тепло, без вопроса.');
        }
        if ($intent === 'unclear') {
            if ($s->counter('unclear_streak') >= 2) {
                $slot = $this->nextSlot($s);

                return new Goal('ask_slot', slot: $slot, fewshotTags: [$this->tagForSlot($slot)], allowedTools: $tools,
                    instruction: 'Не переспрашивать второй раз — продолжить своим вопросом по слоту.');
            }

            return new Goal('unclear', fewshotTags: ['unclear'], allowedTools: [], instruction: 'Переспросить один раз, коротко.');
        }

        // стадия завершения контакта
        if ($s->stage() === 'close_contact' || $s->stage() === 'followup') {
            return $this->closeContactGoal($s, $x, $intent, $tools);
        }

        // вопрос с фактом + следующий слот
        $facts = $this->factsForIntent($intent);
        $slot = $this->nextSlot($s);
        $trigger = $this->pickTrigger($s, $x);
        if ($facts) {
            $tags = array_filter([$this->tagForIntent($intent), $slot ? $this->tagForSlot($slot) : null]);

            return new Goal('answer_and_ask', slot: $slot, facts: $facts, trigger: $trigger, fewshotTags: array_values($tags), allowedTools: $tools,
                constraints: $this->mustNot($facts),
                instruction: 'Ответить на вопрос клиента своими словами из фактов (одна-две мысли), затем мостик и ровно один вопрос по слоту.');
        }

        // просто ответ на вопрос агента → следующий слот
        $goalFacts = [];
        $constraints = [];
        if ($slot && $s->askCount($slot) >= 1) {
            $constraints[] = 'Этот вопрос уже звучал, клиент ушёл от ответа. Спроси иначе: короче, другими словами, можно предложить 2–3 варианта на выбор. Не извиняйся и не говори «повторю вопрос».';
        }
        $city = trim((string) ($x['slot_updates']['city'] ?? ''));
        $pickupFactId = $this->p['pickup_fact_id'] ?? 'delivery_moscow';
        $pickupFact = is_string($pickupFactId) ? $this->kb->fact($pickupFactId) : null;
        $pickupText = is_array($pickupFact) ? implode(' ', array_filter($pickupFact['facts'] ?? [], 'is_string')) : '';
        $stem = mb_strlen($city) > 4 ? mb_substr($city, 0, -1) : $city;
        $aliases = $this->kb->conditions()['delivery']['pickup_city_aliases'] ?? [];
        $configuredMatch = is_array($aliases) && collect($aliases)->contains(fn ($alias) => is_string($alias) && mb_strlen(trim($alias)) >= 3 && mb_stripos($city, trim($alias)) !== false);
        $factMatch = mb_strlen($stem) >= 3 && $pickupText !== '' && mb_stripos($pickupText, $stem) !== false;
        $legacyMoscow = ($this->kb->persona()['company'] ?? '') === 'Союз Пир' && str_contains($pickupText, 'ВДНХ')
            && preg_match('/москв|подмоск|московск/ui', $city);
        $factMatch = $factMatch || $legacyMoscow;
        if ($pickupFact && ($configuredMatch || $factMatch) && ! $s->signal('moscow_pickup_said')) {
            $s->setSignal('moscow_pickup_said', true);
            $goalFacts[] = $pickupFact;
            $constraints[] = ($this->kb->persona()['company'] ?? '') === 'Союз Пир' && str_contains($pickupText, 'ВДНХ')
                ? 'Клиент из Москвы: одной фразой упомяни самовывоз со склада на ВДНХ (или бесплатно до терминала ТК), затем вопрос.'
                : 'Одной фразой упомяни доступный самовывоз или доставку только по указанному факту, затем вопрос.';
        }

        return new Goal('ask_slot', slot: $slot, trigger: $trigger, facts: $goalFacts, constraints: $constraints, fewshotTags: $slot ? [$this->tagForSlot($slot)] : [], allowedTools: $tools,
            instruction: $slot ? 'Коротко отреагировать на слова клиента (одна фраза), затем ровно один вопрос по слоту.' : 'Коротко отреагировать и предложить следующий шаг.');
    }

    private function objectionGoal(LeadState $s, array $x, array $objections, string $intent, array $tools): Goal
    {
        $map = [
            'has_supplier' => ['has_supplier', 'obj_has_supplier'], 'competitor_discount' => ['compare_discount', 'obj_competitor_discount'],
            'cheaper_elsewhere' => ['compare_discount', 'obj_competitor_discount'], 'no_money' => ['ask_leftovers_strategy', 'obj_no_money'],
            'bad_season' => ['ask_leftovers_strategy', 'obj_no_money'], 'too_early' => ['too_early', 'obj_too_early'],
            'fear_prepayment' => ['fear_prepayment', 'obj_fear_prepayment'], 'want_photos' => ['ask_price_list_format', 'obj_want_photos'],
            'returns' => ['ask_returns', 'obj_returns'], 'consignment' => ['ask_consignment', 'obj_deferred'], 'deferred' => ['ask_deferred', 'obj_deferred'],
            'already_ordered' => ['has_supplier', 'obj_already_ordered'], 'zerga_unknown' => ['ask_zerga', 'obj_zerga_unknown'],
            'marketplace_cheaper' => ['marketplace_cheaper', 'obj_marketplace'], 'leftovers' => ['ask_leftovers_strategy', 'obj_leftovers'],
            'delivery_expensive' => ['ask_delivery_cost', 'delivery_cost'], 'far_away' => ['ask_delivery', 'delivery'],
            'waiting_permission' => ['ask_premises', 'fire_safety'], 'budget_declined' => [null, 'budget_declined_ack'],
        ];
        $intentMap = ['has_supplier' => 'has_supplier', 'compare_discount' => 'competitor_discount', 'too_early' => 'too_early', 'marketplace_cheaper' => 'marketplace_cheaper',
            'want_container' => null, 'ask_returns' => 'returns', 'ask_deferred' => 'deferred', 'ask_consignment' => 'consignment', 'fear_prepayment' => 'fear_prepayment', 'ask_leftovers_strategy' => 'leftovers'];

        $key = $objections[0] ?? ($intentMap[$intent] ?? null);
        $factIntent = $key ? ($map[$key][0] ?? $intent) : $intent;
        $tag = $key ? ($map[$key][1] ?? null) : null;
        if ($intent === 'want_container') {
            $factIntent = 'want_container';
            $tag = 'obj_container';
        }
        $facts = $this->factsForIntent($factIntent ?? $intent);
        if ($key === 'budget_declined') {
            $s->inc('budget_asks');
        }
        if ($key === 'too_early') {
            $s->setSlot('timing', $s->slot('timing') ?? 'пока рано');
        }
        $trigger = $this->pickTrigger($s, $x);
        $slot = in_array($key, ['too_early', 'no_money', 'bad_season'], true) ? 'next_contact_at' : $this->nextSlot($s);
        $tags = array_values(array_filter([$tag, $slot ? $this->tagForSlot($slot) : null]));
        $allowed = $tools;
        if (in_array($key, ['too_early', 'no_money', 'bad_season'], true)) {
            $allowed[] = 'set_followup';
        }

        return new Goal('handle_objection', slot: $slot, facts: $facts, trigger: $trigger, fewshotTags: $tags, allowedTools: array_values(array_unique($allowed)),
            constraints: array_merge($this->mustNot($facts), ['не спорить', 'сначала согласиться с чувством клиента, потом аргумент']),
            extras: ['objection' => $key],
            instruction: 'Отработать возражение: признать, дать один аргумент из фактов (и триггер, если уместен), затем ровно один вопрос по слоту.');
    }

    private function closeContactGoal(LeadState $s, array $x, string $intent, array $tools): Goal
    {
        $facts = $this->factsForIntent($intent);
        if ($slot = $this->requiredClientSlot($s)) {
            return new Goal('ask_slot', slot: $slot, facts: $facts, fewshotTags: [$this->tagForSlot($slot)], allowedTools: $tools,
                instruction: 'При необходимости ответить на вопрос клиента и уточнить обязательное поле одним вопросом.');
        }
        // 1) нет контакта → спросить почту, затем мессенджер
        if (! $s->has('email') && ! $s->wasAsked('email')) {
            return new Goal('close_contact', slot: 'email', facts: $facts, fewshotTags: array_filter([$this->tagForIntent($intent), 'ask_email']), allowedTools: $tools,
                instruction: 'Если был вопрос — коротко ответить. Сказать, что отправите прайс на почту, и спросить адрес. Один вопрос.');
        }
        if (! $s->has('messenger') && ! $s->wasAsked('messenger')) {
            return new Goal('close_contact', slot: 'messenger', facts: $facts, fewshotTags: array_filter([$this->tagForIntent($intent), 'ask_messenger', 'other_number_warning']), allowedTools: $tools,
                instruction: 'Сказать, что продублируете в мессенджер, чтобы контакт был, и спросить, какой мессенджер на номере. Предупредить про другой номер. Один вопрос.');
        }
        // 2) контакт есть, прайс не отправлен → send_price
        if ($s->hasContact() && ! $s->flag('price_sent')) {
            return new Goal('close_contact', slot: 'next_contact_at', facts: $facts, fewshotTags: ['price_sent', 'ask_next_contact'], allowedTools: array_values(array_unique(array_merge($tools, ['send_price', 'set_followup']))),
                instruction: 'Вызвать send_price. Сказать, что отправили (куда), одной фразой как читать прайс, и спросить, когда удобно списаться по обратной связи — предложить два варианта. Один вопрос.');
        }
        // 3) прайс отправлен, нет даты
        if ($s->flag('price_sent') && ! $s->has('next_contact_at')) {
            $trigger = $this->pickTrigger($s, $x);

            return new Goal('close_contact', slot: 'next_contact_at', facts: $facts, trigger: $trigger, fewshotTags: ['ask_next_contact'], allowedTools: array_values(array_unique(array_merge($tools, ['set_followup']))),
                instruction: 'Спросить, когда удобно списаться по обратной связи, предложив два варианта. Вызвать set_followup, если клиент уже назвал дату. Один вопрос.');
        }

        // 4) всё есть
        return new Goal('wrap_up', facts: $facts, fewshotTags: ['followup_close', 'price_sent'], allowedTools: array_values(array_unique(array_merge($tools, ['set_followup']))),
            instruction: 'Если клиент назвал дату — вызвать set_followup. Коротко подтвердить договорённость, ответить на вопрос, если был. Без нового вопроса.');
    }

    // ------------------------------------------------------------------ helpers

    public function nextSlot(LeadState $s): ?string
    {
        if ($slot = $this->requiredClientSlot($s)) {
            return $slot;
        }
        $order = $this->p['slot_order'][$s->clientType()] ?? $this->p['slot_order']['unknown'];
        foreach ($order as $slot) {
            if ($s->has($slot)) {
                continue;
            }
            if ($slot === 'budget_range') {
                if ($s->counter('budget_asks') >= $this->limit('budget_asks_max', 2)) {
                    continue;
                }
            } elseif ($slot === 'current_supplier') {
                if ($s->clientType() !== 'experienced' || $s->wasAsked($slot)) {
                    continue;
                }
                // опыт был давно («пару лет назад», «раньше») — текущего поставщика нет, вопрос бессмысленный
                if ($s->signal('experience_old') || preg_match('/назад|раньше|давно|когда-то|в прошлом/ui', (string) $s->slot('experience'))) {
                    continue;
                }
            } elseif (in_array($slot, ['email', 'messenger', 'next_contact_at'], true)) {
                // контакты — только когда остальное спрошено или стадия close_contact
                if (! in_array($s->stage(), ['close_contact', 'followup', 'budget'], true) && $s->counter('turns') < 3) {
                    continue;
                }
                if ($s->wasAsked($slot) && $slot !== 'next_contact_at') {
                    continue;
                }
            } elseif ($s->wasAsked($slot) && $s->counter('turns') > 2) {
                // спросили, клиент не ответил — не долбим, идём дальше (кроме контактов)
                continue;
            }

            return $slot;
        }

        return null;
    }

    private function requiredClientSlot(LeadState $s): ?string
    {
        $stages = ['open', 'qualify', 'need', 'budget', 'close_contact', 'followup', 'handoff'];
        $current = array_search($s->stage(), $stages, true);
        foreach ($this->p['client_fields'] ?? [] as $field) {
            $slot = $field['id'] ?? null;
            $stage = $field['stage'] ?? null;
            $requiredAt = $stage === null ? 0 : array_search($stage, $stages, true);
            if (($field['required'] ?? false) && is_string($slot)
                && $current !== false && $requiredAt !== false && $current >= $requiredAt && ! $s->has($slot) && ! $s->wasRefused($slot)) {
                return $slot;
            }
        }

        return null;
    }

    public function tagForSlot(?string $slot): ?string
    {
        if (! $slot) {
            return null;
        }
        if ($slot === 'budget_range') {
            return 'ask_budget_soft';
        }

        return $this->p['missing_question_tags'][$slot] ?? ('ask_'.$slot);
    }

    private function tagForIntent(string $intent): ?string
    {
        $map = [
            'ask_price_increase' => 'price_increase', 'ask_shortage' => 'shortage', 'ask_delivery' => 'delivery', 'ask_delivery_cost' => 'delivery_cost',
            'ask_sdek' => 'sdek', 'ask_premises' => 'fire_safety', 'ask_license' => 'license_certs', 'ask_certificates' => 'license_certs',
            'ask_markup' => 'markup', 'ask_marking' => 'marking', 'ask_shelf_life' => 'shelf_life', 'ask_reserve' => 'reserve', 'ask_location' => 'showroom',
            'ask_pickup' => 'showroom', 'ask_zerga' => 'zerga', 'ask_selection' => 'offer_selection', 'ask_price_list' => 'price_sent', 'for_self' => 'for_self_ack',
            'ask_price_list_format' => 'obj_want_photos', 'ask_requisites' => 'requisites_sent',
        ];

        return $map[$intent] ?? null;
    }

    public function factsForIntent(string $intent): array
    {
        return $this->kb->factsForIntent($intent, 3);
    }

    private function mustNot(array $facts): array
    {
        $out = [];
        foreach ($facts as $f) {
            foreach ($f['must_not'] ?? [] as $m) {
                $out[] = 'нельзя: '.$m;
            }
        }

        return $out;
    }

    private function closedTopic(?string $id): ?array
    {
        foreach ($this->kb->closedTopics() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return $this->kb->closedTopics()[0] ?? null;
    }

    public function toolsFor(LeadState $s): array
    {
        $always = $this->p['tools_by_stage']['always'] ?? [];
        $byStage = $this->p['tools_by_stage'][$s->stage()] ?? [];
        $tools = array_values(array_unique(array_merge($always, $byStage)));
        $tools = array_values(array_diff($tools, ['escalate'])); // эскалацию решает Policy, а не модель
        if ($s->hasContact() && ! $s->flag('price_sent') && ! in_array($s->stage(), ['handoff', 'spam', 'lost'], true)) {
            $tools[] = 'send_price'; // контакт уже есть — прайс отправляем сразу, не ждём стадии close_contact
        }
        if (in_array('send_price', $tools, true) && ! $s->hasContact()) {
            $tools = array_values(array_diff($tools, ['send_price']));
        }
        if ($s->flag('duplicate_lead') && ! $s->flag('price_sent')) {
            $tools = array_values(array_diff($tools, ['send_price']));
        }

        return $tools;
    }

    /** Выбор триггера: разрешён на стадии, подходит по when/not_when, не исчерпан. */
    public function pickTrigger(LeadState $s, array $x): ?array
    {
        if (($x['sentiment'] ?? 'neutral') === 'negative' || $s->signal('wants_only_prices') || in_array($x['client_intent'] ?? '', ['ask_price_list', 'give_contact'], true)) {
            return null;
        }
        if ($s->counter('turns') - (int) $s->signal('last_trigger_turn') < 3) {
            return null; // не чаще одного приёма на три хода
        }
        $allowed = $this->p['triggers_by_stage'][$s->stage()] ?? [];
        foreach ($allowed as $id) {
            $t = $this->kb->trigger($id);
            if (! $t) {
                continue;
            }
            if ($s->triggerUsed($id) >= (int) ($t['max_uses_per_dialog'] ?? 1)) {
                continue;
            }
            if (! $this->matches($t['when'] ?? [], $s)) {
                continue;
            }
            if (! empty($t['not_when']) && $this->matchesAny($t['not_when'], $s)) {
                continue;
            }

            return $t;
        }

        return null;
    }

    private function matches(array $cond, LeadState $s): bool
    {
        foreach ($cond as $k => $v) {
            if ($k === 'any_of') {
                $ok = false;
                foreach ($v as $alt) {
                    if ($this->matches($alt, $s)) {
                        $ok = true;
                        break;
                    }
                }
                if (! $ok) {
                    return false;
                }

                continue;
            }
            $actual = $this->read($k, $s);
            $vals = is_array($v) ? $v : [$v];
            if (is_array($actual)) {
                if (! array_intersect(array_map('strval', $actual), array_map('strval', $vals))) {
                    return false;
                }
            } elseif (! in_array($actual, $vals, false)) {
                // null в списке — совпадение с пустым
                if (! ($actual === null && in_array(null, $vals, true))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesAny(array $cond, LeadState $s): bool
    {
        foreach ($cond as $k => $v) {
            $actual = $this->read($k, $s);
            $vals = is_array($v) ? $v : [$v];
            if (is_array($actual)) {
                if (array_intersect(array_map('strval', $actual), array_map('strval', $vals))) {
                    return true;
                }
            } elseif (in_array($actual, $vals, false) && $actual !== null) {
                return true;
            }
        }

        return false;
    }

    private function read(string $path, LeadState $s): mixed
    {
        return match (true) {
            $path === 'client_type' => $s->clientType(),
            $path === 'stage' => $s->stage(),
            str_starts_with($path, 'slots.') => $s->slot(substr($path, 6)),
            str_starts_with($path, 'signals.') => $s->signal(substr($path, 8)),
            str_starts_with($path, 'flags.') => $s->flag(substr($path, 6)),
            default => null,
        };
    }

    private function escalationReason(LeadState $s, array $x): ?array
    {
        $intent = $x['client_intent'] ?? '';
        $reasons = collect($this->kb->escalation()['reasons'] ?? [])->keyBy('id');
        $pick = fn (string $id) => $reasons->get($id);

        // «хочу живого человека»: передаём, когда это явная просьба (intent), либо намёк прозвучал второй раз.
        // Фраза вроде «если вопросы пойдут серьёзные, подключите человека» — ещё не просьба.
        if (! empty($x['wants_human']) || $intent === 'want_human') {
            $s->inc('wants_human_hits');
        }
        if ($intent === 'want_human' || $s->counter('wants_human_hits') >= 2) {
            return $pick('want_human');
        }
        if ($s->counter('negative_streak') >= $this->limit('negative_streak_escalate', 2)) {
            return $pick('negative_sentiment');
        }
        // отсрочка/реализация/возврат с настаиванием: второй раз подряд
        $payIntents = ['ask_deferred', 'ask_consignment', 'ask_returns'];
        $payObj = ['deferred', 'consignment', 'returns'];
        $prev = $s->signal('prev_intent');
        $s->setSignal('prev_intent', $intent);
        if ((in_array($intent, $payIntents, true) || array_intersect($x['objections'] ?? [], $payObj)) && in_array($prev, $payIntents, true)) {
            return $pick('deferred_insist');
        }
        if (preg_match('/(\d+)/u', (string) $s->slot('budget_range'), $m)) {
            $amount = (int) $m[1];
            if (str_contains((string) $s->slot('budget_range'), 'млн')) {
                $amount *= 1_000_000;
            } elseif ($amount < 10000) {
                $amount *= 1000;
            }
            if ($amount >= (int) ($this->kb->conditions()['escalation_amount_rub'] ?? 500000) && ! $s->signal('big_order_notified')) {
                $s->setSignal('big_order_notified', true);

                return ($pick('big_order') ?? ['id' => 'big_order']) + ['mode' => 'notify'];
            }
        }
        // претензия по браку: свой случай («у вас… не взлетела», «половина коробки брак»), а не общий вопрос о качестве
        $text = $x['_text'] ?? '';
        $ownCase = preg_match('/(не взлет|не сработал|не стрельну|брак|претензи|не отработал|отсырел)/u', $text)
            && preg_match('/(у вас|ваш|прошл|купил|брал|взял|заказывал|коробк|партии)/u', $text);
        if ($ownCase && ! $s->signal('defect_handled')) {
            $s->setSignal('defect_handled', true);

            return $pick('defect_claim');
        }
        if ($intent === 'want_container' && $prev === 'want_container') {
            return $pick('container_import');
        }
        if (in_array('waiting_permission', $x['objections'] ?? [], true) && $prev && str_starts_with($prev, 'ask_premises')) {
            return $pick('premises_approval');
        }
        if ($s->counter('unknown_insist') >= $this->limit('unknown_fact_insist_escalate', 2)) {
            return $pick('unknown_fact_insist');
        }

        return null;
    }

    private function handoffGoal(LeadState $s, array $reason): Goal
    {
        $s->setFlag('escalate', true);
        $s->setFlag('escalate_reason', $reason['id']);
        $s->setStage('handoff');
        $tags = $reason['id'] === 'deferred_insist' ? ['handoff_deferred'] : ['handoff'];

        return new Goal('handoff', fewshotTags: $tags, allowedTools: ['escalate', 'send_price'],
            extras: ['reason' => $reason['id'], 'message_idea' => $this->escalationText($reason)],
            instruction: 'Сообщить клиенту, что передаёте менеджеру, и когда ждать ответа. Один вопрос не задавать. Вызвать tool escalate.');
    }

    private function escalationText(array $reason): string
    {
        $rt = $this->kb->escalation()['defaults']['response_time_text'] ?? 'сегодня';

        return str_replace('{response_time}', $rt, $reason['message_idea'] ?? '');
    }

    // ------------------------------------------------------------------ 3. outcome / missing

    public function computeOutcome(LeadState $s, bool $dialogEnded = false): ?string
    {
        if ($s->stage() === 'spam') {
            return 'spam';
        }
        if ($s->flag('do_not_disturb')) {
            return 'declined';
        }
        if ($s->flag('escalate')) {
            return 'handoff';
        }
        if ($s->counter('followups_sent') >= $this->limit('followup_max_auto', 2) && $dialogEnded) {
            return 'stale';
        }
        if ($s->flag('price_sent') && $s->hasContact() && $s->has('next_contact_at')) {
            return 'full_contact';
        }
        if (in_array($s->signal('last_client_intent'), ['too_early'], true) && $s->has('next_contact_at')) {
            return 'paused';
        }
        if ($s->hasContact() || $s->has('phone')) {
            return 'partial_contact';
        }

        return $dialogEnded ? 'partial_contact' : null;
    }

    public function computeMissing(LeadState $s): array
    {
        $out = [];
        foreach (LeadState::PRIORITY_SLOTS as $slot) {
            if ($s->has($slot)) {
                continue;
            }
            if ($slot === 'current_supplier' && $s->clientType() !== 'experienced') {
                continue;
            }
            if ($s->clientType() === 'for_self' && in_array($slot, ['experience', 'outlet_type', 'current_supplier'], true)) {
                continue;
            }
            if ($s->clientType() === 'prices_only' && in_array($slot, ['purpose', 'experience', 'outlet_type', 'categories', 'current_supplier', 'timing'], true)) {
                continue;
            }
            $reason = 'not_asked';
            if ($slot === 'budget_range' && $s->counter('budget_asks') >= 2) {
                $reason = 'declined';
            } elseif ($s->wasRefused($slot)) {
                $reason = 'declined';
            } elseif ($s->askCount($slot) >= 1 && $s->signal('last_asked_slot') !== $slot) {
                $reason = 'evaded'; // спрашивали, клиент ушёл от ответа — менеджеру стоит вернуться к этому
            } elseif ($s->counter('turns') < 6 || $s->flag('escalate')) {
                $reason = 'no_chance';
            }
            $out[] = ['slot' => $slot, 'reason' => $reason, 'suggested_question' => $this->kb->suggestedQuestion($slot, $s->clientType())];
        }

        foreach ($this->p['client_fields'] ?? [] as $field) {
            $slot = $field['id'] ?? null;
            if (($field['required'] ?? false) && is_string($slot) && ! in_array($slot, LeadState::PRIORITY_SLOTS, true) && ! $s->has($slot)) {
                $out[] = ['slot' => $slot, 'reason' => $s->wasRefused($slot) ? 'declined' : 'not_asked',
                    'suggested_question' => 'Уточните: '.($field['name'] ?? $slot)];
            }
        }

        return $out;
    }
}
