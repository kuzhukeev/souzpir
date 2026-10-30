import { Eye, RotateCcw } from 'lucide-react';
import { themes, type PreviewTheme } from './preview-state';

export default function PreviewToolbar({
  screen,
  state,
  states,
  onStateChange,
  theme,
  onThemeChange,
  onReset,
}: {
  screen: 'login' | 'attention';
  state: string;
  states: { value: string; label: string }[];
  onStateChange: (value: string) => void;
  theme: PreviewTheme;
  onThemeChange: (value: PreviewTheme) => void;
  onReset: () => void;
}) {
  return (
    <aside
      aria-label="Настройки предпросмотра"
      className="relative z-30 border-b border-(--color-border) bg-(--color-surface) px-4 py-2 text-xs text-(--color-ink)"
    >
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span className="inline-flex items-center gap-1.5 font-semibold">
          <Eye size={15} className="text-(--color-brand-primary)" />
          <span className="sm:hidden">Макеты · демо</span>
          <span className="hidden sm:inline">Макеты · демонстрационные данные</span>
        </span>
        <nav aria-label="Макеты" className="flex gap-3">
          <a
            href={`/preview/login?theme=${theme}`}
            aria-current={screen === 'login' ? 'page' : undefined}
            className={`rounded px-1 py-1 text-(--color-link) ${screen === 'login' ? 'font-bold underline underline-offset-4' : ''}`}
          >
            Вход
          </a>
          <a
            href={`/preview/attention?theme=${theme}`}
            aria-current={screen === 'attention' ? 'page' : undefined}
            className={`rounded px-1 py-1 text-(--color-link) ${screen === 'attention' ? 'font-bold underline underline-offset-4' : ''}`}
          >
            Кабинет
          </a>
        </nav>
        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
          <label className="flex items-center gap-1.5">
            <span className="sr-only sm:not-sr-only">Тема</span>
            <select
              aria-label="Тема оформления"
              value={theme}
              onChange={(event) => onThemeChange(event.target.value as PreviewTheme)}
              className="max-w-36 rounded-md border border-(--color-control-border) bg-(--color-surface) px-2 py-1"
            >
              {themes.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex items-center gap-1.5">
            <span className="sr-only sm:not-sr-only">Состояние</span>
            <select
              aria-label="Состояние экрана"
              value={state}
              onChange={(event) => onStateChange(event.target.value)}
              className="max-w-44 rounded-md border border-(--color-control-border) bg-(--color-surface) px-2 py-1"
            >
              {states.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>
          <button
            type="button"
            aria-label="Сбросить демо"
            title="Сбросить демо"
            onClick={onReset}
            className="rounded px-1 py-1 text-(--color-link) underline-offset-2 hover:underline"
          >
            <RotateCcw size={15} className="sm:hidden" />
            <span className="hidden sm:inline">Сбросить демо</span>
          </button>
        </div>
      </div>
    </aside>
  );
}
