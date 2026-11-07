<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot as Knowledge;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
        // Контактные слоты универсальны для любого бренда; остальные оптовые слоты — только для подтверждённого прототип-бренда.
        foreach (['name', 'email', 'messenger', 'next_contact_at'] as $s) {
            $slotProps[$s] = ['type' => ['string', 'null']];
        }
        if (LeadState::isPrototypeBrand($this->kb->persona()['company'] ?? null)) {
            foreach (['city', 'purpose', 'experience', 'outlet_type', 'outlet_floor', 'current_supplier', 'competitor_discount', 'budget_range', 'timing', 'legal'] as $s) {
                $slotProps[$s] = ['type' => ['string', 'null']];
            }
            $slotProps['categories'] = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];
            $slotProps['brands_interest'] = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];
        }
        foreach ($this->clientFields() as $field) {
            $slotProps[$field['id']] = $field['type'] === 'select' && $field['options'] !== []
                ? ['type' => ['string', 'null'], 'enum' => [...$field['options'], null]]
                : ['type' => ['string', 'null']];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['slot_updates', 'client_intent', 'client_questions', 'objections', 'sentiment', 'wants_only_prices', 'asked_if_bot',
                'wants_human', 'do_not_disturb', 'off_topic', 'prompt_injection', 'closed_topic', 'unknown_question', 'client_claims', 'refused_slots', 'confidence'],
            'properties' => [
                'slot_updates' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($slotProps), 'properties' => $slotProps],
                'client_intent' => ['type' => 'string', 'enum' => $intents],
                'client_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'objections' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $this->objections()]],
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

    public function extract(#[\SensitiveParameter] LeadState $state, #[\SensitiveParameter] array $history, #[\SensitiveParameter] string $clientMessage, #[\SensitiveParameter] AiContext $ctx): array
    {
        $tenant = app(TenantContext::class);
        if ($tenant->organizationId !== $ctx->organizationId || $tenant->brandId !== $ctx->brandId || $ctx->organizationId !== $this->prompt->organizationId || $ctx->brandId !== $this->prompt->brandId || $ctx->family !== 'seller' || $ctx->purpose !== 'extractor' || $ctx->objectType !== 'conversation') {
            throw new LogicException('Extractor context does not match its active tenant and conversation.');
        }
        foreach (['conversation_content', 'client_profile', 'knowledge_internal'] as $class) {
            if (! in_array($class, $ctx->dataClasses, true)) {
                throw new LogicException('Extractor context omits a required data class.');
            }
        }
        if (! DB::table('conversations')->where('id', $ctx->objectId)->where('organization_id', $ctx->organizationId)->where('brand_id', $ctx->brandId)->exists()) {
            throw new LogicException('Extractor conversation is outside the active tenant.');
        }
        $knowledge = app(KnowledgeRepository::class)->forRelease($tenant, $this->kb->releaseId(), false);
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, $this->prompt->version);
        $verified = new self($this->llm, $knowledge, $prompt, $this->model, $this->temperature, $this->agentName);

        return $verified->extractVerified($state, $history, $clientMessage, $ctx);
    }

    private function extractVerified(#[\SensitiveParameter] LeadState $state, #[\SensitiveParameter] array $history, #[\SensitiveParameter] string $clientMessage, #[\SensitiveParameter] AiContext $ctx): array
    {
        $result = $this->llm->complete($this->buildRequest($state, $history, $clientMessage), $ctx);

        return $this->interpretResult($state, $clientMessage, $result);
    }

    /** Pure request construction; this does not authenticate inputs or authorize Gateway. */
    public function buildRequest(#[\SensitiveParameter] LeadState $state, #[\SensitiveParameter] array $history, #[\SensitiveParameter] string $clientMessage): AiRequest
    {
        $this->assertIntents();
        $system = Blade::render($this->prompt->body, [
            'card' => $state->cardText(),
            'intents' => $this->kb->intents(),
            'closedTopics' => $this->kb->closedTopics(),
            'objections' => $this->objections(),
            'agentName' => $this->agentName,
            'persona' => $this->kb->persona(),
            'policy' => $this->kb->policy(),
        ], deleteCachedView: true);

        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($history, -6) as $h) {
            $messages[] = ['role' => $h['role'] === 'agent' ? 'assistant' : 'user', 'content' => $h['text']];
        }
        $messages[] = ['role' => 'user', 'content' => "СООБЩЕНИЕ КЛИЕНТА ДЛЯ РАЗБОРА:\n".$clientMessage];

        return new AiRequest(
            messages: $messages,
            model: $this->model,
            temperature: $this->temperature,
            responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => 'extraction', 'strict' => true, 'schema' => $this->schema()]],
            maxTokens: 900,
            promptVersion: $this->prompt->version,
            promptChecksum: $this->prompt->checksum,
        );
    }

    /** Pure interpretation; the caller must authenticate the origin of the result. */
    public function interpretResult(#[\SensitiveParameter] LeadState $state, #[\SensitiveParameter] string $clientMessage, #[\SensitiveParameter] AiResult $res): array
    {
        if ($res->error !== null) {
            $code = $res->error['code'] ?? 'unknown';
            if ($code === 'in_progress') {
                throw new ExtractionPending($res->aiCallId);
            }
            if ($code !== 'empty_response') {
                throw new ExtractionUncertain($res->aiCallId, $code);
            }
        }
        $data = $res->ok() ? $res->json() : null;
        if ($data) {
            try {
                $data = $this->normalize($data);
            } catch (InvalidArgumentException) {
                $data = null;
            }
        }
        if (! $data || ! $this->valid($data)) {
            return $this->recoverContacts($state, $clientMessage, $this->fallback($clientMessage, $res->error['code'] ?? 'invalid json'));
        }
        $data = $this->recoverContacts($state, $clientMessage, $data);
        $data['_meta'] = ['model' => $res->model, 'latency_ms' => $res->latencyMs, 'cost_usd' => $res->costMicros === null ? null : $res->costMicros / 1_000_000, 'usage' => $res->usage];

        return $data;
    }

    /** Дозаполнить обязательные ключи, если модель их опустила (не строгие провайдеры). */
    public function normalize(#[\SensitiveParameter] array $d): array
    {
        $objections = $d['objections'] ?? [];
        if (! is_array($objections) || ! array_is_list($objections)) {
            throw new InvalidArgumentException('Invalid objections.');
        }
        foreach ((array) $objections as $objection) {
            if (! is_string($objection)) {
                throw new InvalidArgumentException('Invalid objection.');
            }
        }
        if (isset($d['client_intent']) && ! is_string($d['client_intent'])) {
            throw new InvalidArgumentException('Invalid intent.');
        }
        $slots = $d['slot_updates'] ?? [];
        if (! is_array($slots)) {
            throw new InvalidArgumentException('Invalid slot updates.');
        }
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

    public function valid(#[\SensitiveParameter] array $data): bool
    {
        try {
            $validator = new Validator;
            $r = $validator->validate(json_decode(json_encode($data)), json_decode(json_encode($this->schema())));

            return $r->isValid();
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertIntents(): void
    {
        $intents = $this->kb->intents();
        if (! array_is_list($intents) || $intents === []) {
            throw new LogicException('Published knowledge requires extractor intents.');
        }
        $ids = [];
        foreach ($intents as $intent) {
            if (! is_array($intent) || ! is_string($intent['id'] ?? null) || trim($intent['id']) === '' || in_array($intent['id'], $ids, true)) {
                throw new LogicException('Invalid extractor intent IDs.');
            }
            $ids[] = $intent['id'];
        }
        if (! in_array('unclear', $ids, true)) {
            throw new LogicException('Published knowledge requires the unclear intent.');
        }
    }

    /** Опубликованные дополнительные поля бренда (D-5.1), валидные для сбора данных. */
    private function clientFields(): array
    {
        return collect($this->kb->policy()['client_fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && is_string($field['id'] ?? null) && trim($field['id']) !== '')
            ->map(fn ($field) => ['id' => $field['id'], 'type' => $field['type'] ?? 'text', 'options' => array_values(array_filter((array) ($field['options'] ?? []), 'is_string'))])
            ->values()->all();
    }

    private function objections(): array
    {
        return $this->prompt->version === 'v2'
            ? array_values(array_diff(self::OBJECTIONS, ['zerga_unknown']))
            : self::OBJECTIONS;
    }

    private function recoverContacts(#[\SensitiveParameter] LeadState $state, #[\SensitiveParameter] string $message, #[\SensitiveParameter] array $data): array
    {
        preg_match_all('/[A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])?)+/iu', $message, $matches);
        $emails = [];
        foreach ($matches[0] ?? [] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[mb_strtolower($candidate)] = $candidate;
            }
        }
        $email = count($emails) === 1 ? reset($emails) : null;
        $previousEmail = $state->slot('email');
        if ($email !== null && (! is_string($previousEmail) || mb_strtolower($previousEmail) !== mb_strtolower($email))) {
            $data['slot_updates']['email'] = $email;
        } elseif (array_key_exists('email', $data['slot_updates'])) {
            $data['slot_updates']['email'] = null;
        }
        $messenger = $data['slot_updates']['messenger'] ?? null;
        if (is_string($messenger) && preg_match('/\[(?:email|phone|телефон)\]/iu', $messenger)) {
            $data['slot_updates']['messenger'] = null;
        }

        return $data;
    }

    /** Если LLM недоступен — минимальный разбор регулярками, чтобы диалог не падал. */
    private function fallback(#[\SensitiveParameter] string $msg, string $err): array
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
