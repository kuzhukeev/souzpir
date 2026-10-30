import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AttentionPreview, { type AttentionState } from '@/features/Preview/AttentionPreview';
import PreviewToolbar from '@/features/Preview/PreviewToolbar';
import { usePreviewSettings } from '@/features/Preview/preview-state';
import { Notice } from '@/features/Preview/ui';

const states = [
  { value: 'full', label: 'События' },
  { value: 'empty', label: 'Всё в порядке' },
  { value: 'loading', label: 'Загрузка' },
  { value: 'error', label: 'Ошибка' },
];
const stateKeys = ['full', 'empty', 'loading', 'error'] as const;
export default function Attention() {
  const { state, setState, theme, setTheme } = usePreviewSettings<AttentionState>(
    stateKeys,
    'full',
    'pyro',
  );
  const [notice, setNotice] = useState('');
  const [revision, setRevision] = useState(0);
  return (
    <div className="flex min-h-dvh flex-col bg-(--color-bg) font-(family-name:--font-sans) text-(--color-ink)">
      <Head title="Требует вашего действия · макет" />
      <a
        href="#preview-main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-2 focus:z-[90] focus:rounded focus:bg-white focus:p-3"
      >
        К содержимому
      </a>
      <PreviewToolbar
        screen="attention"
        state={state}
        states={states}
        onStateChange={(value) => setState(value as AttentionState)}
        theme={theme}
        onThemeChange={setTheme}
        onReset={() => {
          setRevision(revision + 1);
          setState('full');
          setNotice('Демонстрационные события восстановлены.');
        }}
      />
      <AttentionPreview
        key={revision}
        state={state}
        onStateChange={setState}
        theme={theme}
        onNotice={setNotice}
      />
      <Notice message={notice} onClose={() => setNotice('')} />
    </div>
  );
}
