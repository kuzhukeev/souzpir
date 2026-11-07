<?php

namespace App\Seller\Engine;

/**
 * LeadState — состояние лида (п. 6.2 ТЗ). Хранится в leads.state как JSON.
 * Класс оборачивает массив и даёт типизированные хелперы.
 */
class LeadState
{
    public const SLOTS = [
        'name', 'city', 'purpose', 'experience', 'outlet_type', 'outlet_floor', 'categories', 'brands_interest',
        'current_supplier', 'competitor_discount', 'budget_range', 'timing', 'legal', 'email', 'messenger', 'next_contact_at',
    ];

    public const PRIORITY_SLOTS = [
        'city', 'purpose', 'experience', 'outlet_type', 'categories', 'current_supplier', 'budget_range', 'timing', 'email', 'messenger', 'next_contact_at',
    ];

    public const STAGES = ['open', 'qualify', 'need', 'budget', 'close_contact', 'followup', 'handoff', 'lost', 'done', 'spam'];

    public const OUTCOMES = ['full_contact', 'partial_contact', 'handoff', 'paused', 'declined', 'stale', 'spam'];

    /** Опубликованные дополнительные поля (D-5.1) заполняются только для подтверждённого прототип-бренда. */
    public const PROTOTYPE_BRAND_COMPANY = 'Союз Пир';

    public array $data;

    public static function isPrototypeBrand(?string $company): bool
    {
        return $company === self::PROTOTYPE_BRAND_COMPANY;
    }

    public static function slotLabels(): array
    {
        return [
            'name' => 'имя', 'city' => 'город', 'purpose' => 'цель', 'experience' => 'опыт', 'outlet_type' => 'точка',
            'outlet_floor' => 'этаж', 'categories' => 'категории', 'brands_interest' => 'бренды', 'current_supplier' => 'поставщик',
            'competitor_discount' => 'скидка конкурента', 'budget_range' => 'бюджет', 'timing' => 'сроки', 'legal' => 'юрстатус',
            'email' => 'почта', 'messenger' => 'мессенджер', 'next_contact_at' => 'следующий контакт',
        ];
    }

    public function __construct(?array $data = null)
    {
        $this->data = array_replace_recursive(self::defaults(), $data ?? []);
        // массивы, которые не должны мержиться рекурсивно
        foreach (['promises', 'missing', 'unknown_questions'] as $k) {
            $this->data[$k] = $data[$k] ?? [];
        }
        $this->data['signals']['objections'] = $data['signals']['objections'] ?? [];
        $this->data['signals']['client_claims'] = $data['signals']['client_claims'] ?? [];
        $this->data['signals']['triggers_used'] = $data['signals']['triggers_used'] ?? [];
        $this->data['signals']['asked_slots'] = $data['signals']['asked_slots'] ?? [];
        $this->data['slots']['categories'] = $data['slots']['categories'] ?? [];
        $this->data['slots']['brands_interest'] = $data['slots']['brands_interest'] ?? [];
    }

    public static function defaults(): array
    {
        return [
            'stage' => 'open',
            'slots' => array_fill_keys(self::SLOTS, null),
            'counters' => [
                'budget_asks' => 0, 'turns' => 0, 'followups_sent' => 0, 'guard_regens' => 0,
                'off_topic_streak' => 0, 'closed_topic_streak' => 0, 'negative_streak' => 0,
                'unknown_facts' => 0, 'unknown_insist' => 0, 'unclear_streak' => 0,
            ],
            'signals' => [
                'last_client_intent' => null, 'objections' => [], 'sentiment' => 'neutral',
                'wants_only_prices' => false, 'asked_if_bot' => false, 'client_claims' => [],
                'triggers_used' => [], 'asked_slots' => [], 'last_unknown_topic' => null,
                'client_questions' => [],
            ],
            'promises' => [],
            'flags' => [
                'escalate' => false, 'escalate_reason' => null, 'do_not_disturb' => false,
                'duplicate_lead' => false, 'price_sent' => false, 'requisites_sent' => false, 'introduced' => false,
            ],
            'outcome' => null,
            'missing' => [],
            'unknown_questions' => [],
            'client_type' => 'unknown',
            'summary' => null,
        ];
    }

    // ---- доступ ----
    public function slot(string $k): mixed
    {
        return $this->data['slots'][$k] ?? null;
    }

    public function setSlot(string $k, mixed $v): void
    {
        $this->data['slots'][$k] = $v;
    }

    public function has(string $slot): bool
    {
        $v = $this->slot($slot);

        return ! ($v === null || $v === '' || $v === []);
    }

    public function stage(): string
    {
        return $this->data['stage'];
    }

    public function setStage(string $s): void
    {
        $this->data['stage'] = $s;
    }

    public function counter(string $k): int
    {
        return (int) ($this->data['counters'][$k] ?? 0);
    }

    public function inc(string $k, int $by = 1): void
    {
        $this->data['counters'][$k] = $this->counter($k) + $by;
    }

    public function resetCounter(string $k): void
    {
        $this->data['counters'][$k] = 0;
    }

    public function flag(string $k): mixed
    {
        return $this->data['flags'][$k] ?? null;
    }

    public function setFlag(string $k, mixed $v): void
    {
        $this->data['flags'][$k] = $v;
    }

    public function signal(string $k): mixed
    {
        return $this->data['signals'][$k] ?? null;
    }

    public function setSignal(string $k, mixed $v): void
    {
        $this->data['signals'][$k] = $v;
    }

    public function addObjection(string $o): void
    {
        if (! in_array($o, $this->data['signals']['objections'], true)) {
            $this->data['signals']['objections'][] = $o;
        }
    }

    public function hasObjection(string $o): bool
    {
        return in_array($o, $this->data['signals']['objections'], true);
    }

    public function addClaim(string $c): void
    {
        $this->data['signals']['client_claims'][] = $c;
    }

    public function addPromise(string $text): void
    {
        $this->data['promises'][] = ['text' => $text, 'created_at' => now()->toIso8601String(), 'done' => false];
    }

    public function addUnknownQuestion(string $q): void
    {
        $this->data['unknown_questions'][] = $q;
        $this->inc('unknown_facts');
    }

    /** Слоты, по которым второй заход другой формулировкой допустим. Остальные (бюджет, поставщик, контакты) спрашиваем один раз. */
    public const RE_ASKABLE = ['city', 'purpose', 'experience', 'outlet_type', 'categories', 'timing'];

    public function markAsked(string $slot): void
    {
        if (! in_array($slot, $this->data['signals']['asked_slots'], true)) {
            $this->data['signals']['asked_slots'][] = $slot;
        }
        $this->data['signals']['asked_counts'][$slot] = $this->askCount($slot) + 1;
        $this->data['signals']['last_asked_slot'] = $slot;
    }

    public function askCount(string $slot): int
    {
        return (int) ($this->data['signals']['asked_counts'][$slot] ?? 0);
    }

    /** Клиент явно отказался отвечать («не скажу», «давайте без этого»). */
    public function refuseSlot(string $slot): void
    {
        $this->data['signals']['refused_slots'][$slot] = true;
    }

    public function wasRefused(string $slot): bool
    {
        return ! empty($this->data['signals']['refused_slots'][$slot]);
    }

    /** «Больше не спрашивать»: явный отказ, либо исчерпан лимит заходов (2 для мягких слотов, 1 для остальных). */
    public function wasAsked(string $slot): bool
    {
        if ($this->wasRefused($slot)) {
            return true;
        }
        $limit = in_array($slot, self::RE_ASKABLE, true) ? 2 : 1;

        return $this->askCount($slot) >= $limit;
    }

    public function triggerUsed(string $id): int
    {
        return (int) ($this->data['signals']['triggers_used'][$id] ?? 0);
    }

    public function useTrigger(string $id): void
    {
        $this->data['signals']['triggers_used'][$id] = $this->triggerUsed($id) + 1;
    }

    public function clientType(): string
    {
        return $this->data['client_type'];
    }

    public function setClientType(string $t): void
    {
        $this->data['client_type'] = $t;
    }

    public function outcome(): ?string
    {
        return $this->data['outcome'];
    }

    public function setOutcome(?string $o): void
    {
        $this->data['outcome'] = $o;
    }

    public function hasContact(): bool
    {
        return $this->has('email') || $this->has('messenger');
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /** Краткая карточка для промптов. */
    public function cardText(): string
    {
        $lines = [];
        foreach (self::slotLabels() as $k => $label) {
            $v = $this->slot($k);
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $lines[] = $label.': '.(is_array($v) ? implode(', ', $v) : $v);
        }
        if (! $lines) {
            $lines[] = 'пока ничего не известно';
        }
        $lines[] = 'тип клиента: '.$this->clientType().'; стадия: '.$this->stage();
        if ($this->data['signals']['objections']) {
            $lines[] = 'возражения: '.implode(', ', $this->data['signals']['objections']);
        }
        if ($this->data['signals']['client_claims']) {
            $lines[] = 'утверждения клиента (не менять условия): '.implode('; ', $this->data['signals']['client_claims']);
        }
        if ($this->data['promises']) {
            $lines[] = 'обещания: '.implode('; ', array_map(fn ($p) => $p['text'], $this->data['promises']));
        }
        if ($this->flag('price_sent')) {
            $lines[] = 'прайс уже отправлен';
        }

        return implode("\n", $lines);
    }
}
