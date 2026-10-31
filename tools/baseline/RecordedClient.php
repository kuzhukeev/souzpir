<?php

declare(strict_types=1);

namespace Souzpir\Baseline;

use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmResult;

/** v2 only: capture through an IPC broker, or strict network-free replay. */
final class RecordedClient implements LlmClient
{
    private int $sequence = 0;
    private array $calls = [];
    private int $resumePrefix = 0;
    private bool $prefixOnly = false;
    private ?string $prefixProvenance = null;
    public bool $failed = false;
    public ?array $failureLocation = null;

    public function __construct(
        public readonly ContactNormalizer $normalizer,
        private readonly string $directory,
        private readonly string $mode,
        private readonly string $runId,
        private readonly string $caseId,
        private readonly string $phase = 'dialogue',
        private readonly ?\Closure $broker = null,
    ) {
        if (!in_array($mode, ['capture', 'replay', 'test'], true)) {
            throw new \InvalidArgumentException('Unsupported recording mode.');
        }
        if ($mode !== 'test' && $broker !== null) {
            throw new \InvalidArgumentException('Injected broker is restricted to synthetic tests.');
        }
        $prefix = getenv('BASELINE_RESUME_PREFIX');
        if ($prefix !== false) {
            if (!preg_match('/^(0|[1-9][0-9]{0,2})$/', $prefix) || (int) $prefix > 200) {
                throw new \RuntimeException('Invalid resume prefix.');
            }
            $this->resumePrefix = (int) $prefix;
            $this->prefixOnly = getenv('BASELINE_PREFIX_ONLY') === '1';
            $this->prefixProvenance = getenv('BASELINE_RESUME_PROVENANCE') ?: null;
            if (!in_array($this->prefixProvenance, ['recorded', 'synthetic'], true)
                || ($this->prefixOnly ? $mode !== 'replay' : !in_array($mode, ['capture', 'test'], true))
                || ($mode === 'capture' && $this->prefixProvenance !== 'recorded')
                || ($mode === 'test' && $this->prefixProvenance !== 'synthetic')
                || (!$this->prefixOnly && !is_dir($directory)) || is_link($directory)) {
                throw new \RuntimeException('Resume provenance or mode differs.');
            }
            $files = is_dir($directory) ? array_values(array_diff(scandir($directory), ['.', '..'])) : [];
            $expected = $this->resumePrefix === 0 ? [] : array_map(static fn (int $i): string => sprintf('%04d.json', $i), range(1, $this->resumePrefix));
            if ($files !== $expected) {
                throw new \RuntimeException('Resume prefix is incomplete or contains extra files.');
            }
            foreach ($files as $file) {
                if (!is_file($directory.'/'.$file) || is_link($directory.'/'.$file)) {
                    throw new \RuntimeException('Unsafe resume prefix file.');
                }
            }
        }
        if ($mode !== 'replay' && $this->resumePrefix === 0) {
            if (file_exists($directory)) {
                throw new \RuntimeException('Recording directory already exists.');
            }
            if (!mkdir($directory, 0700, true)) {
                throw new \RuntimeException('Cannot create recording directory.');
            }
        }
    }

    public static function wire(array $request): array
    {
        $o = $request['options'];
        $body = [
            'model' => $o['model'],
            'messages' => $request['messages'],
            'temperature' => $o['temperature'] ?? 0.5,
            'max_tokens' => $o['max_tokens'] ?? 700,
            'usage' => ['include' => true],
            'reasoning' => $o['reasoning'] ?? ['effort' => 'low'],
        ];
        if (!empty($o['response_format'])) {
            if (str_starts_with((string) $o['model'], 'anthropic/')) {
                $schema = $o['response_format']['json_schema']['schema'] ?? null;
                $body['messages'][] = ['role' => 'user', 'content' => 'Ответь строго одним JSON-объектом без пояснений и без '.str_repeat(chr(96), 3).($schema ? ' по схеме: '.json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '')];
            } else {
                $body['response_format'] = $o['response_format'];
                $body['provider'] = ['require_parameters' => true];
            }
        }
        if (!empty($o['tools'])) {
            $body['tools'] = $o['tools'];
            $body['tool_choice'] = $o['tool_choice'] ?? 'auto';
        }
        return FixtureClient::canonical($body);
    }

    /** Transport compatibility only; original donor options and responses stay intact. */
    private static function uniqueToolDefinitions(array $tools): array
    {
        $seen = [];
        $unique = [];
        foreach ($tools as $tool) {
            $value = (array) $tool;
            $function = (array) ($value['function'] ?? []);
            $name = $function['name'] ?? null;
            if (($value['type'] ?? null) !== 'function' || !is_string($name) || $name === '') {
                throw new \RuntimeException('Invalid tool definition.');
            }
            $canonical = FixtureClient::json(FixtureClient::canonical($tool));
            if (isset($seen[$name])) {
                if ($seen[$name] !== $canonical) {
                    throw new \RuntimeException('Conflicting same-name tool definitions.');
                }
                continue;
            }
            $seen[$name] = $canonical;
            $unique[] = $tool;
        }
        return $unique;
    }

    private static function semanticWire(array|object $body): array
    {
        $base = (array) $body;
        unset($base['provider']);
        if (array_key_exists('tools', $base)) {
            if (!is_array($base['tools']) || !array_is_list($base['tools'])) {
                throw new \RuntimeException('Tool definitions must be a list.');
            }
            $base['tools'] = self::uniqueToolDefinitions($base['tools']);
        }
        if (array_key_exists('stream', $base)) {
            if ($base['stream'] !== false) {
                throw new \RuntimeException('Streaming transport is forbidden.');
            }
            unset($base['stream']);
        }
        if (array_key_exists('plugins', $base)) {
            if ($base['plugins'] !== []) {
                throw new \RuntimeException('Provider plugins are forbidden.');
            }
            unset($base['plugins']);
        }
        return FixtureClient::canonical($base);
    }

    public function chat(array $messages, array $options = []): LlmResult
    {
        try {
            return $this->perform($messages, $options);
        } catch (\Throwable $error) {
            $this->failureLocation = ['type'=>$error::class, 'file'=>basename($error->getFile()), 'line'=>$error->getLine()];
            $this->failed = true;
            // Donor catches/logs errors. Never include provider response or raw input.
            throw new \RuntimeException('Baseline call failed; capture stopped without retry.');
        }
    }

    private function perform(array $messages, array $options): LlmResult
    {
        if ($this->failed) {
            throw new \RuntimeException('Recording already failed.');
        }
        $request = $this->normalizer->request($messages, $options);
        $hash = hash('sha256', FixtureClient::json($request));
        $sequence = ++$this->sequence;
        $path = $this->directory.'/'.sprintf('%04d.json', $sequence);
        $body = self::wire($request);
        if ($this->prefixOnly && $sequence === $this->resumePrefix + 1) {
            // Stop before a missing call can reach IPC. No donor error handling,
            // fallback, request retry, or network is involved in prefix validation.
            echo json_encode(['op'=>'prefix_ready', 'case_id'=>$this->caseId, 'run_id'=>$this->runId,
                'calls'=>$this->calls, 'consumed_sequences'=>array_column($this->calls, 'sequence'),
                'next_request'=>['request_sha256'=>$hash,
                    'request_body_sha256'=>hash('sha256', FixtureClient::json($body))],
                'network_calls'=>0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
            exit(0);
        }
        if ($this->mode === 'replay' || $sequence <= $this->resumePrefix) {
            if (!is_file($path)) {
                throw new \RuntimeException('Missing replay call.');
            }
            $call = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($call['schema_version'] ?? null) !== 1 || !in_array($call['provenance'] ?? null, ['recorded', 'synthetic'], true)
                || ($this->prefixProvenance !== null && ($call['provenance'] ?? null) !== $this->prefixProvenance)
                || ($call['evidence']['run_id'] ?? '') !== $this->runId
                || ($call['evidence']['case_id'] ?? '') !== $this->caseId
                || ($call['evidence']['sequence'] ?? null) !== $sequence
                || ($call['evidence']['phase'] ?? '') !== $this->phase
                || ($call['request_sha256'] ?? '') !== $hash
                || FixtureClient::json(json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR)->request) !== FixtureClient::json($request)
                || ($call['response_sha256'] ?? '') !== hash('sha256', FixtureClient::json($call['response']))) {
                throw new \RuntimeException('Replay identity, request or response differs.');
            }
            $object = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            $wire = $object->evidence->request_body ?? null;
            if ((!is_array($wire) && !is_object($wire))
                || ($call['evidence']['request_body_sha256'] ?? '') !== hash('sha256', FixtureClient::json($wire))
                || FixtureClient::json(self::semanticWire($wire)) !== FixtureClient::json(self::semanticWire($body))) {
                throw new \RuntimeException('Replay wire hash or semantic body differs.');
            }
            $response = $this->normalizer->normalize($call['response'], true);
            if (FixtureClient::json($response) !== FixtureClient::json($call['response'])) {
                throw new \RuntimeException('Replay response is not already safely normalized.');
            }
        } else {
            $body = self::wire($request);
            if ($this->broker !== null) {
                $reply = ($this->broker)(['request' => $request, 'body' => $body, 'sequence' => $sequence]);
                $actualBody = $reply['request_body'] ?? null;
            } else {
                fwrite(STDOUT, json_encode(['op' => 'call', 'case_id' => $this->caseId, 'sequence' => $sequence, 'phase' => $this->phase, 'request' => $request, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
                fflush(STDOUT);
                $line = fgets(STDIN, 4_000_001);
                $reply = $line === false ? [] : json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $actualBody = $line === false ? null : (json_decode($line, false, 512, JSON_THROW_ON_ERROR)->request_body ?? null);
            }
            if (($reply['ok'] ?? false) !== true || !is_array($reply['data'] ?? null)) {
                throw new \RuntimeException('Broker refused request.');
            }
            if (!is_array($actualBody) && !is_object($actualBody)) {
                throw new \RuntimeException('Broker did not attest the actual wire body.');
            }
            if (FixtureClient::json(self::semanticWire($actualBody)) !== FixtureClient::json(self::semanticWire($body))) {
                throw new \RuntimeException('Broker changed semantic request fields.');
            }
            $actualBody = FixtureClient::canonical($actualBody);
            $data = $this->normalizer->normalize($reply['data']);
            $usage = $data['usage'] ?? [];
            $choice = $data['choices'][0] ?? [];
            $msg = $choice['message'] ?? [];
            $tools = [];
            foreach ($msg['tool_calls'] ?? [] as $tool) {
                $arguments = $tool['function']['arguments'] ?? '{}';
                $tools[] = [
                    'id' => $tool['id'] ?? throw new \RuntimeException('Missing tool call identity.'),
                    'name' => $tool['function']['name'] ?? '',
                    'arguments' => is_string($arguments) ? (json_decode($arguments, true, 512, JSON_THROW_ON_ERROR) ?? []) : (array) $arguments,
                ];
            }
            $result = new LlmResult(
                content: is_array($msg['content'] ?? null) ? implode('', array_column($msg['content'], 'text')) : ($msg['content'] ?? null),
                toolCalls: $tools,
                usage: $usage,
                model: $data['model'] ?? '',
                latencyMs: 0,
                costUsd: (float) ($usage['cost'] ?? throw new \RuntimeException('Missing usage cost.')),
                raw: $data,
            );
            $response = FixtureClient::canonical(get_object_vars($result));
            $call = [
                'schema_version' => 1,
                'provenance' => $this->mode === 'capture' ? 'recorded' : 'synthetic',
                'provider' => $this->mode === 'capture' ? 'openrouter' : 'fake IPC broker',
                'request_sha256' => $hash, 'request' => $request,
                'response_sha256' => hash('sha256', FixtureClient::json($response)), 'response' => $response,
                'evidence' => [
                    'run_id' => $this->runId, 'case_id' => $this->caseId, 'sequence' => $sequence, 'phase' => $this->phase,
                    'captured_at' => $reply['captured_at'], 'http_status' => 200,
                    'provider_request_id' => $data['id'] ?? '', 'provider_model' => $data['model'] ?? '',
                    'request_body' => $actualBody, 'request_body_sha256' => hash('sha256', FixtureClient::json($actualBody)),
                    'seed_requested' => null,
                    'budget' => $reply['budget'] ?? null, 'pricing' => $reply['pricing'] ?? null,
                    'usage' => ['prompt_tokens' => $usage['prompt_tokens'] ?? null, 'completion_tokens' => $usage['completion_tokens'] ?? null,
                        'total_tokens' => $usage['total_tokens'] ?? null, 'cost_usd' => is_string($usage['cost']) ? $usage['cost'] : json_encode($usage['cost'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)],
                ],
            ];
            $handle = fopen($path, 'xb');
            if ($handle === false) {
                throw new \RuntimeException('Refusing to overwrite a recording.');
            }
            try {
                $bytes = FixtureClient::json(FixtureClient::canonical($call));
                if (fwrite($handle, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('Incomplete evidence write.');
                }
                if (!fflush($handle) || !fsync($handle)) {
                    throw new \RuntimeException('Evidence durability failed.');
                }
            } finally {
                fclose($handle);
            }
        }
        $this->calls[] = ['sequence' => $sequence, 'path' => 'llm/'.$this->caseId.'/'.basename($path),
            'sha256' => hash_file('sha256', $path), 'phase' => $this->phase];
        return new LlmResult(...$response);
    }

    public function calls(): array { return $this->calls; }

    public function assertExhausted(): void
    {
        if ($this->failed || count(glob($this->directory.'/*.json') ?: []) !== $this->sequence) {
            throw new \RuntimeException('Failed or unconsumed recording.');
        }
    }
}
