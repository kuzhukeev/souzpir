<?php

namespace App\Seller\Engine;

/**
 * Цель хода — что сказать (не как). Формируется Policy, передаётся Responder и Guard.
 */
class Goal
{
    public function __construct(
        public string $kind,                 // intro_and_ask | answer_and_ask | answer_only | ask_slot | handle_objection | unknown_fact |
        // closed_topic | decline_off_topic | decline_injection | silent | bot_disclosure | handoff | opt_out |
        // close_contact | wrap_up | busy_callback | small_talk | unclear | followup_open | duplicate_lead | spam_silent
        public ?string $slot = null,         // какой слот спросить (ровно один или null)
        public array $facts = [],            // записи facts.yaml (1–3)
        public ?array $trigger = null,       // запись triggers.yaml
        public array $allowedTools = [],     // имена tools, доступных на этом ходу
        public array $constraints = [],      // текстовые ограничения для Responder
        public array $fewshotTags = [],      // теги примеров
        public array $extras = [],           // доп. контекст: closed_topic entry, escalation idea, promise text, question text
        public ?string $instruction = null,  // одна фраза: что сделать в этой реплике
    ) {}

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'slot' => $this->slot,
            'facts' => array_map(fn ($f) => $f['id'], $this->facts),
            'trigger' => $this->trigger['id'] ?? null,
            'allowed_tools' => $this->allowedTools,
            'constraints' => $this->constraints,
            'fewshot_tags' => $this->fewshotTags,
            'instruction' => $this->instruction,
            'extras' => array_diff_key($this->extras, ['closed_topic' => 1]),
        ];
    }
}
