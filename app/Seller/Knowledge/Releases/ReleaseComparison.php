<?php

namespace App\Seller\Knowledge\Releases;

use App\Seller\Knowledge\KnowledgeSnapshot;

final class ReleaseComparison
{
    public function compare(KnowledgeSnapshot $before, KnowledgeSnapshot $after): array
    {
        $left = $before->toArray();
        $right = $after->toArray();
        $sections = [
            'facts' => ['facts'],
            'conditions' => ['conditions'],
            'topics' => ['intents', 'triggers', 'closed_topics'],
            'examples' => ['fewshot'],
        ];
        $result = [];
        foreach ($sections as $section => $files) {
            $old = $this->entries($left, $files);
            $new = $this->entries($right, $files);
            $changes = [];
            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
                $from = $old[$key] ?? null;
                $to = $new[$key] ?? null;
                if ($this->normalized($from) === $this->normalized($to)) {
                    continue;
                }
                $changes[] = ['key' => $key, 'type' => $from === null ? 'added' : ($to === null ? 'removed' : 'changed'), 'before' => $from, 'after' => $to];
            }
            $result[$section] = ['totalBefore' => count($old), 'totalAfter' => count($new), 'changes' => $changes];
        }

        return $result;
    }

    private function entries(array $snapshot, array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            foreach ($snapshot[$file] as $index => $value) {
                $label = is_array($value) ? ($value['id'] ?? ($value['tag'] ?? null)) : null;
                $key = is_string($index) ? $index : (is_string($label) ? $label.':'.$index : (string) $index);
                $entries[$file.':'.$key] = $value;
            }
        }

        return $entries;
    }

    private function normalized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalized(...), $value);
    }
}
