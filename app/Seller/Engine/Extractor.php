<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Seller\Knowledge\KnowledgeSnapshot as Knowledge;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Blade;
use LogicException;
use Opis\JsonSchema\Validator;

/**
 * Extractor — быстрый LLM, response_format=json_schema strict (п. 5, 6.3 ТЗ).
 * Не додумывает: только то, что явно следует из слов клиента.
 */
class Extractor
{
    public function __construct(private readonly AiGateway $llm, private readonly Knowledge $kb, private readonly ExtractorPrompt $prompt, private readonly string $model, private readonly float $temperature, private readonly string $agentName) {}

    public function schema(): array
    {
        $intents = $this->kb->intentIds();
        $slotProps = [];
        foreach (['city', 'name', 'purpose', 'experience', 'outlet_type', 'outlet_floor', 'current_supplier', 'competitor_discount', 'budget_range', 'timing', 'legal', 'email', 'messenger', 'next_contact_at'] as $s) {
            $slotProps[$s] = ['type' => ['string', 'null']];
        }
        $slotProps['categories'] = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];
        $slotProps['brands_interest'] = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['slot_updates', 'client_intent', 'client_questions', 'objections', 'sentiment', 'wants_only_prices', 'asked_if_bot',
                'wants_human', 'do_not_disturb', 'off_topic', 'prompt_injection', 'closed_topic', 'unknown_question', 'client_claims', 'refused_slots', 'confidence'],
            'properties' => [
                'slot_updates' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($slotProps), 'properties' => $slotProps],
                'client_intent' => ['type' => 'string', 'enum' => $intents],
                'client_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'objections' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::OBJECTIONS]],
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                'wants_only_prices' => ['type' => 'boolean'],
                'asked_if_bot' => ['type' => 'boolean'],
                'wants_human' => ['type' => 'boolean'],
                'do_not_disturb' => ['type' => 'boolean'],
                'off_topic' => ['type' => 'boolean'],
                'prompt_injection' => ['type' => 'boolean'],
                'closed_topic' => ['type' => ['string', 'null'], 'enum' => array_merge(array_map(fn ($c) => $c['id'], $this->kb->closedTopics()), [null])],
                'unknown_question' => ['type' => ['string', 'null']],
                'client_claims' => ['type' => 'array', 'items' => ['type' => 'string']],
                'refused_slots' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($slotProps)]],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    public const OBJECTIONS = [
        'has_supplier', 'competitor_discount', 'cheaper_elsewhere', 'no_money', 'bad_season', 'too_early', 'fear_prepayment',
        'want_photos', 'returns', 'consignment', 'deferred', 'already_ordered', 'zerga_unknown', 'marketplace_cheaper',
        'already_received', 'budget_declined', 'delivery_expensive', 'far_away', 'waiting_permission', 'leftovers',
    ];

    public function extract(LeadState $state, array $history, string $clientMessage, AiContext $ctx): array
    {
        if ($ctx->organizationId !== $this->prompt->organizationId || $ctx->brandId !== $this->prompt->brandId || $ctx->family !== 'seller' || $ctx->purpose !== 'extractor') {
            throw new LogicException('Extractor context does not match its prompt.');
        }
        // Revalidate before compiling: deserialized or otherwise forged DTOs must not execute arbitrary Blade.
        ExtractorPrompt::verified(new TenantContext($ctx->organizationId, $ctx->brandId, source: 'job'), $this->prompt->version, $this->prompt->checksum, $this->prompt->body);
        $system = Blade::render($this->prompt->body, [
            'card' => $state->cardText(),
            'intents' => $this->kb->intents(),
            'closedTopics' => $this->kb->closedTopics(),
            'objections' => self::OBJECTIONS,
            'agentName' => $this->agentName,
        ], deleteCachedView: true);

        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($history, -6) as $h) {
            $messages[] = ['role' => $h['role'] === 'agent' ? 'assistant' : 'user', 'content' => $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => "СООБЩЕНИЕ КЛИЕНТА ДЛЯ РАЗБОРА:\n".$clientMessage];

        $res = $this->llm->complete(new AiRequest(
            messages: $messages,
            model: $this->model,
            temperature: $this->temperature,
            responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => 'extraction', 'strict' => true, 'schema' => $this->schema()]],
            maxTokens: 900,
            promptVersion: $this->prompt->version,
            promptChecksum: $this->prompt->checksum,
        ), $ctx);

        $data = $res->ok() ? $res->json() : null;
        if ($data) {
            $data = $this->normalize($data);
        }
        if (! $data || ! $this->valid($data)) {
            return $this->fallback($clientMessage, $res->error['code'] ?? 'invalid json');
        }
        $data['_meta'] = ['model' => $res->model, 'latency_ms' => $res->latencyMs, 'cost_usd' => $res->costMicros === null ? null : $res->costMicros / 1_000_000, 'usage' => $res->usage];

        return $data;
    }

    /** Дозаполнить обязательные ключи, если модель их опустила (не строгие провайдеры). */
    public function normalize(array $d): array
    {
        $slots = (array) ($d['slot_updates'] ?? []);
        foreach ($this->schema()['properties']['slot_updates']['required'] as $k) {
            $slots[$k] = $slots[$k] ?? null;
        }
        $d['slot_updates'] = $slots;
        $defaults = ['client_intent' => 'unclear', 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'wants_only_prices' => false,
            'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false, 'prompt_injection' => false,
            'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.5];
        foreach ($defaults as $k => $v) {
            $d[$k] = $d[$k] ?? $v;
        }
        $d['objections'] = array_values(array_intersect((array) $d['objections'], self::OBJECTIONS));
        if (! in_array($d['client_intent'], $this->kb->intentIds(), true)) {
            $d['client_intent'] = 'unclear';
        }

        return $d;
    }

    public function valid(array $data): bool
    {
        try {
            $validator = new Validator;
            $r = $validator->validate(json_decode(json_encode($data)), json_decode(json_encode($this->schema())));

            return $r->isValid();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Если LLM недоступен — минимальный разбор регулярками, чтобы диалог не падал. */
    private function fallback(string $msg, string $err): array
    {
        $low = mb_strtolower($msg);
        $intent = 'unclear';
        $slots = [];
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/u', $msg, $m)) {
            $slots['email'] = $m[0];
            $intent = 'give_contact';
        }
        if (preg_match('/(ватсап|whatsapp|вотсап|телеграм|telegram|макс\b)/u', $low, $m)) {
            $slots['messenger'] = $m[1];
            $intent = 'give_contact';
        }
        if (preg_match('/прайс/u', $low)) {
            $intent = 'ask_price_list';
        }
        if (preg_match('/не пишите|неактуально|отпишите/u', $low)) {
            $intent = 'opt_out';
        }

        return [
            'slot_updates' => $slots, 'client_intent' => $intent, 'client_questions' => [], 'objections' => [],
            'sentiment' => 'neutral', 'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false,
            'do_not_disturb' => $intent === 'opt_out', 'off_topic' => false, 'prompt_injection' => false, 'closed_topic' => null,
            'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.2,
            '_meta' => ['model' => 'fallback', 'error' => $err],
        ];
    }
}
