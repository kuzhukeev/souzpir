<?php

namespace App\Seller\Settings\Techniques;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TechniqueEditor
{
    public function view(TenantContext $context): array
    {
        $draft = DB::table('knowledge_releases')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('status', 'draft')->first(['id', 'version']);
        try {
            $snapshot = $draft ? app(KnowledgeRepository::class)->forRelease($context, $draft->id, true)
                : app(KnowledgeRepository::class)->published($context);
        } catch (KnowledgeNotReady) {
            return ['techniques' => [], 'release' => null, 'version' => 0, 'draft' => false, 'statistics' => []];
        }
        $data = $snapshot->toArray();
        $stages = $data['policy']['triggers_by_stage'] ?? [];
        $statistics = $this->statistics($context);
        $techniques = array_map(function (array $item) use ($stages, $statistics): array {
            $id = $item['id'];
            $active = collect($stages)->contains(fn ($ids) => in_array($id, $ids, true));

            return [
                'id' => $id, 'name' => $item['name'] ?? $id, 'idea' => $item['idea'] ?? '',
                'examples' => $item['examples'] ?? [], 'max_uses_per_dialog' => $item['max_uses_per_dialog'] ?? 1,
                'when' => $item['when'] ?? [], 'not_when' => $item['not_when'] ?? [],
                'enabled' => $active, 'is_base' => $item['is_base'] ?? false,
                'uses' => $statistics[$id]['uses'] ?? 0, 'dialogs' => $statistics[$id]['dialogs'] ?? 0,
                'conversion_with' => $statistics[$id]['conversion_with'] ?? null,
                'conversion_without' => $statistics[$id]['conversion_without'] ?? null,
            ];
        }, $data['triggers']);

        return ['techniques' => $techniques, 'release' => $draft?->id ?? $snapshot->releaseId(),
            'version' => $draft?->version ?? 0, 'draft' => $draft !== null, 'statistics' => []];
    }

    public function save(TenantContext $context, string $id, int $version, array $input): void
    {
        [$draft, $data] = $this->source($context, $version);
        $found = false;
        foreach ($data['triggers'] as &$item) {
            if ($item['id'] !== $id) {
                continue;
            }
            $found = true;
            $item['name'] = $input['name'];
            $item['idea'] = $input['idea'];
            $item['examples'] = array_values(array_filter(array_map('trim', $input['examples'])));
            $item['max_uses_per_dialog'] = $input['max_uses_per_dialog'];
            $item['is_base'] = $input['is_base'];
            foreach (['when', 'not_when'] as $part) {
                $advanced = array_filter($item[$part] ?? [], fn ($key) => $key === 'any_of', ARRAY_FILTER_USE_KEY);
                $item[$part] = $advanced + $this->conditions($input[$part] ?? []);
            }
            $this->enabled($data['policy']['triggers_by_stage'], $item, $input['enabled']);
            break;
        }
        unset($item);
        abort_unless($found, 404);
        $this->write($context, $draft, $version, $data);
    }

    public function quiet(TenantContext $context, int $version): void
    {
        [$draft, $data] = $this->source($context, $version);
        foreach ($data['triggers'] as &$item) {
            if (! ($item['is_base'] ?? false)) {
                $this->enabled($data['policy']['triggers_by_stage'], $item, false);
            }
        }
        unset($item);
        $this->write($context, $draft, $version, $data);
    }

    public function publish(TenantContext $context, string $release, int $version): void
    {
        app(KnowledgeRepository::class)->publish($context, $release, $version);
    }

    private function source(TenantContext $context, int $version): array
    {
        $draft = DB::table('knowledge_releases')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('status', 'draft')->first(['id', 'version']);
        if ($draft) {
            abort_unless($draft->version === $version, 409);

            return [$draft, app(KnowledgeRepository::class)->forRelease($context, $draft->id, true)->toArray()];
        }
        abort_unless($version === 0, 409);

        return [null, app(KnowledgeRepository::class)->published($context)->toArray()];
    }

    private function write(TenantContext $context, ?object $draft, int $version, array $data): void
    {
        if ($draft) {
            app(KnowledgeRepository::class)->saveDraft($context, $draft->id, $version, $data);
        } else {
            app(KnowledgeRepository::class)->createDraft($context, $data);
        }
    }

    private function conditions(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $field = $row['field'];
            if (isset($result[$field])) {
                throw ValidationException::withMessages(['conditions' => 'Условие не должно повторяться.']);
            }
            $values = array_values(array_filter(array_map('trim', explode(',', $row['values'])), fn ($v) => $v !== ''));
            if ($values === []) {
                throw ValidationException::withMessages(['conditions' => 'Заполните значение условия.']);
            }
            $result[$field] = array_map(fn (string $value): mixed => match (strtolower($value)) {
                'true' => true, 'false' => false, 'null' => null, default => $value,
            }, $values);
        }

        return $result;
    }

    private function enabled(array &$stages, array &$item, bool $enabled): void
    {
        $id = $item['id'];
        $active = [];
        foreach ($stages as $stage => &$ids) {
            if (in_array($id, $ids, true)) {
                $active[] = $stage;
            }
            $ids = array_values(array_filter($ids, fn ($value) => $value !== $id));
        }
        unset($ids);
        if ($active !== []) {
            $item['editor_stages'] = $active;
        }
        if (! $enabled) {
            return;
        }
        $restore = $item['editor_stages'] ?? [];
        if ($restore === []) {
            throw ValidationException::withMessages(['enabled' => 'Для приёма не заданы стадии в политике.']);
        }
        foreach ($restore as $stage) {
            $stages[$stage][] = $id;
        }
    }

    private function statistics(TenantContext $context): array
    {
        $conversations = DB::table('conversations')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('created_at', '>=', now()->subDays(30))
            ->pluck('outcome', 'id');
        $messages = DB::table('messages')->where('organization_id', $context->organizationId)
            ->where('brand_id', $context->brandId)->where('role', 'agent')->where('created_at', '>=', now()->subDays(30))
            ->get(['conversation_id', 'meta']);
        $uses = [];
        $dialogIds = [];
        foreach ($messages as $message) {
            $meta = json_decode($message->meta ?: '{}', true);
            $id = $meta['goal']['trigger']['id'] ?? null;
            if (! is_string($id) || ! $conversations->has($message->conversation_id)) {
                continue;
            }
            $uses[$id] = ($uses[$id] ?? 0) + 1;
            $dialogIds[$id][$message->conversation_id] = true;
        }
        $converted = fn ($outcome) => in_array($outcome, ['full_contact', 'partial_contact'], true);
        $result = [];
        foreach ($uses as $id => $count) {
            $with = array_keys($dialogIds[$id]);
            $without = $conversations->keys()->diff($with);
            $withConverted = collect($with)->filter(fn ($key) => $converted($conversations->get($key)))->count();
            $withoutConverted = $without->filter(fn ($key) => $converted($conversations->get($key)))->count();
            $result[$id] = ['uses' => $count, 'dialogs' => count($with),
                'conversion_with' => count($with) ? round(100 * $withConverted / count($with)) : null,
                'conversion_without' => $without->count() ? round(100 * $withoutConverted / $without->count()) : null];
        }

        return $result;
    }
}
