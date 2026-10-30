import { Head } from '@inertiajs/react';
import { useState } from 'react';
import LoginPreview, { type LoginState } from '@/features/Preview/LoginPreview';
import PreviewToolbar from '@/features/Preview/PreviewToolbar';
import { usePreviewSettings } from '@/features/Preview/preview-state';
import { Notice } from '@/features/Preview/ui';

const states = [
  { value: 'default', label: 'Вход' },
  { value: 'invalid', label: 'Неверные данные' },
  { value: 'locked', label: 'После 5 попыток' },
  { value: 'mfa', label: 'Проверка MFA' },
  { value: 'loading', label: 'Загрузка' },
];
const stateKeys = ['default', 'invalid', 'locked', 'mfa', 'loading'] as const;
export default function Login() {
  const { state, setState, theme, setTheme } = usePreviewSettings<LoginState>(
    stateKeys,
    'default',
    'neutral',
  );
  const [notice, setNotice] = useState('');
  const [revision, setRevision] = useState(0);
  return (
    <div className="min-h-dvh bg-(--color-bg) font-(family-name:--font-sans) text-(--color-ink)">
      <Head title="Вход · макет" />
      <PreviewToolbar
        screen="login"
        state={state}
        states={states}
        onStateChange={(value) => setState(value as LoginState)}
        theme={theme}
        onThemeChange={setTheme}
        onReset={() => {
          setRevision(revision + 1);
          setState('default');
          setNotice('Форма и демонстрационное состояние сброшены.');
        }}
      />
      <LoginPreview key={revision} state={state} onStateChange={setState} onNotice={setNotice} />
      <Notice message={notice} onClose={() => setNotice('')} />
    </div>
  );
}
