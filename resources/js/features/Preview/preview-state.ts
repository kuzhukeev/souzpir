import { useEffect, useState } from 'react';

export type PreviewTheme = 'confectionery' | 'pyro' | 'neutral';
export const themes: { value: PreviewTheme; label: string }[] = [
  { value: 'neutral', label: 'Нейтральная' },
  { value: 'pyro', label: 'Пиротехника' },
  { value: 'confectionery', label: 'Кондитерская' },
];

export function usePreviewSettings<T extends string>(
  states: readonly T[],
  initialState: T,
  initialTheme: PreviewTheme,
) {
  const query = new URLSearchParams(window.location.search);
  const requestedState = query.get('state') as T;
  const requestedTheme = query.get('theme') as PreviewTheme;
  const [state, setState] = useState<T>(
    states.includes(requestedState) ? requestedState : initialState,
  );
  const [theme, setTheme] = useState<PreviewTheme>(
    themes.some((item) => item.value === requestedTheme) ? requestedTheme : initialTheme,
  );
  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    const next = new URL(window.location.href);
    next.searchParams.set('state', state);
    next.searchParams.set('theme', theme);
    window.history.replaceState(window.history.state, '', next);
  }, [state, theme]);
  return { state, setState, theme, setTheme };
}
