import { Eye, RotateCcw } from 'lucide-react';
import { themes, type PreviewTheme } from './preview-state';
import PreviewSelect from './PreviewSelect';

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
      className="relative z-30 shrink-0 border-b border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm text-(--color-ink)"
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
        <div className="grid w-full grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2 sm:ml-auto sm:flex sm:w-auto">
          <div className="flex min-w-0 items-center gap-2">
            <span className="hidden lg:inline">Тема</span>
            <PreviewSelect
              label="Тема оформления"
              value={theme}
              onChange={(value) => onThemeChange(value as PreviewTheme)}
              options={themes}
              className="w-full min-w-0 sm:w-44"
            />
          </div>
          <div className="flex min-w-0 items-center gap-2">
            <span className="hidden lg:inline">Состояние</span>
            <PreviewSelect
              label="Состояние экрана"
              value={state}
              onChange={onStateChange}
              options={states}
              className="w-full min-w-0 sm:w-48"
            />
          </div>
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
