<?php

namespace App\Seller\Knowledge;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/** Immutable release snapshot; accessors preserve the prototype Knowledge behavior. */
final class KnowledgeSnapshot
{
    public const FILES = ['conditions', 'facts', 'triggers', 'guard_rules', 'policy', 'intents', 'escalation', 'persona', 'fewshot', 'closed_topics'];

    public function __construct(private readonly array $data, private readonly string $releaseId, private readonly string $checksum)
    {
        foreach (self::FILES as $file) {
            if (! array_key_exists($file, $data) || ! is_array($data[$file])) {
                throw new InvalidArgumentException('Incomplete knowledge snapshot: '.$file);
            }
        }
        if (array_diff(array_keys($data), self::FILES)) {
            throw new InvalidArgumentException('Unknown knowledge namespace.');
        }
        $this->assertJsonValue($data);
    }

    private function assertJsonValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                $this->assertJsonValue($entry);
            }

            return;
        }
        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Knowledge snapshots contain only finite JSON values.');
        }
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    public function all(string $file): array
    {
        return $this->data[$file] ?? [];
    }

    public function conditions(): array
    {
        return $this->data['conditions'];
    }

    public function persona(): array
    {
        return $this->data['persona'];
    }

    public function policy(): array
    {
        return $this->data['policy'];
    }

    public function guardRules(): array
    {
        return $this->data['guard_rules'];
    }

    public function escalation(): array
    {
        return $this->data['escalation'];
    }

    public function intents(): array
    {
        return $this->data['intents'];
    }

    public function intentIds(): array
    {
        return array_values(array_map(fn ($i) => $i['id'], $this->intents()));
    }

    public function intent(string $id): ?array
    {
        foreach ($this->intents() as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    public function fact(string $id): ?array
    {
        foreach ($this->data['facts'] as $f) {
            if ($f['id'] === $id) {
                unset($f['fact_ids']);

                return $this->resolveFact($f);
            }
        }

        return null;
    }

    /** @return array<int,array> */
    public function factsForIntent(string $intentId, int $limit = 3): array
    {
        $intent = $this->intent($intentId);
        if (! $intent) {
            return [];
        }
        $out = [];
        foreach (array_slice($intent['facts'] ?? [], 0, $limit) as $fid) {
            if (array_key_exists($fid, $intent['fact_overrides'] ?? [])) {
                foreach ($this->data['facts'] as $group) {
                    if ($group['id'] !== $fid) {
                        continue;
                    }
                    $allowed = $intent['fact_overrides'][$fid];
                    $filtered = [];
                    foreach ($group['facts'] as $index => $text) {
                        if (in_array($group['fact_ids'][$index] ?? null, $allowed, true)) {
                            $filtered[] = $text;
                        }
                    }
                    $group['facts'] = $filtered;
                    unset($group['fact_ids']);
                    if ($filtered !== []) {
                        $out[] = $this->resolveFact($group);
                    }
                    break;
                }
            } elseif ($f = $this->fact($fid)) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** Все строки фактов (для проверки copy-paste в Guard). */
    public function allFactSentences(): array
    {
        $out = [];
        foreach ($this->data['facts'] as $f) {
            foreach ($f['facts'] ?? [] as $s) {
                $out[] = $this->resolvePlaceholders($s);
            }
        }

        return $out;
    }

    public function trigger(string $id): ?array
    {
        foreach ($this->data['triggers'] as $t) {
            if ($t['id'] === $id) {
                $t['idea'] = $this->resolvePlaceholders($t['idea'] ?? '');

                return $t;
            }
        }

        return null;
    }

    public function triggers(): array
    {
        return array_map(fn ($t) => $this->trigger($t['id']), $this->data['triggers']);
    }

    public function closedTopics(): array
    {
        return $this->data['closed_topics'];
    }

    /** @return array<int,array{tag:string,text:string,client_type:string,confidence:float}> */
    public function fewshot(array $tags, ?string $clientType = null, int $limit = 6, float $minConf = 0.7): array
    {
        $ex = $this->data['fewshot']['examples'] ?? [];
        $out = [];
        foreach ($tags as $tag) {
            foreach ($ex as $e) {
                if (($e['tag'] ?? '') !== $tag || ($e['confidence'] ?? 0) < $minConf) {
                    continue;
                }
                $ct = $e['client_type'] ?? 'all';
                if ($clientType && $ct !== 'all' && $ct !== $clientType) {
                    continue;
                }
                $out[] = $e;
            }
        }
        // если по типу клиента ничего — берём без фильтра
        if (! $out && $clientType) {
            return $this->fewshot($tags, null, $limit, $minConf);
        }

        return array_slice($out, 0, $limit);
    }

    public function badExamples(): array
    {
        return $this->data['fewshot']['bad_examples'] ?? [];
    }

    public function suggestedQuestion(string $slot, ?string $clientType = null): ?string
    {
        $tag = $this->policy()['missing_question_tags'][$slot] ?? null;
        if (! $tag) {
            return null;
        }
        $ex = $this->fewshot([$tag], $clientType, 1, 0.7);

        return $ex[0]['text'] ?? null;
    }

    // ---------- плейсхолдеры ----------

    private function resolveFact(array $f): array
    {
        $f['facts'] = array_map(fn ($s) => $this->resolvePlaceholders($s), $f['facts'] ?? []);

        return $f;
    }

    public function resolvePlaceholders(string $s): string
    {
        return preg_replace_callback('/\{cond:([a-zA-Z0-9_.]+)\}/u', function ($m) {
            $v = Arr::get($this->conditions(), $m[1]);

            return $this->formatCond($m[1], $v);
        }, $s);
    }

    private function formatCond(string $path, mixed $v): string
    {
        if ($v === null) {
            return '[нет данных]';
        }
        if ($path === 'discount_grid' && is_array($v)) {
            $cap = (int) ($this->conditions()['max_pct_unapproved'] ?? 100);
            $approved = ! empty($this->conditions()['approved_by']);
            $parts = [];
            foreach ($v as $row) {
                $pct = (int) $row['pct'];
                if (! $approved && $pct > $cap) {
                    continue;
                }
                $parts[] = 'от '.$this->rub($row['from']).' — '.$pct.'%';
            }

            return implode(', ', $parts).($approved ? '' : ' (ориентир, к утверждению)');
        }
        if ($path === 'box_discounts' && is_array($v)) {
            $names = ['zerga' => 'Zerga', 'volk' => 'Волк', 'premier_salute' => 'Премьер Салют'];
            $parts = [];
            foreach ($v as $k => $pct) {
                $parts[] = ($names[$k] ?? $k).' '.$pct.'%';
            }

            return implode(', ', $parts);
        }
        if ($path === 'promotions' && is_array($v)) {
            return implode('; ', array_map(fn ($p) => $p['name'].': '.$p['text'].' (до '.$this->date($p['until']).')', $v));
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_numeric($v) && str_contains($path, '_rub')) {
            return $this->rub($v);
        }
        if ($v instanceof \DateTimeInterface) {
            return $this->date($v);
        }

        return (string) $v;
    }

    private function rub(int|float|string $v): string
    {
        $v = (int) $v;
        if ($v >= 1000 && $v % 1000 === 0) {
            return ($v / 1000).' тыс. руб.';
        }

        return number_format($v, 0, '', ' ').' руб.';
    }

    private function date(mixed $d): string
    {
        if ($d instanceof \DateTimeInterface) {
            return $d->format('d.m.Y');
        }

        return (string) $d;
    }
}
