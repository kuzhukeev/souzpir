import type { Model } from '@/types/Vision/Questions';
import { card } from './styles';

export function Models({
  models,
  selected,
  onChange,
  limit,
}: {
  limit?: string | null;
  models: Model[];
  selected: Record<string, string>;
  onChange: (models: Record<string, string>) => void;
}) {
  return (
    <fieldset className={card}>
      <legend className="sr-only">Модели</legend>
      <h2 className="text-xl font-bold">Модели</h2>
      <p className="mt-2 text-sm text-(--color-ink-muted)">Выбрано: {Object.keys(selected).length} из {limit ?? "без ограничения"}.</p>
      <div className="mt-4 space-y-3">
        {models.map((model) => (
          <label
            key={model.id}
            className={`flex min-h-11 items-center gap-3 rounded-lg border border-(--color-border) p-3 text-sm ${model.status === 'active' ? 'cursor-pointer' : 'text-(--color-ink-muted)'}`}
          >
            <input
              type="checkbox"
              disabled={model.status !== 'active'}
              checked={selected[model.provider] === model.id}
              onChange={(event) => {
                const next = { ...selected };
                if (event.target.checked) next[model.provider] = model.id;
                else delete next[model.provider];
                onChange(next);
              }}
            />
            <span className="min-w-0 flex-1 break-words">{model.id}</span>
            {model.status !== 'active' && <span>Скоро</span>}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
