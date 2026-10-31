import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { profileCopy } from './copy';
import type { SharedPropsV1 } from '@/types/generated/data';

export function useProfileText() {
  const { translations } = usePage<SharedPropsV1 & Record<string, unknown>>().props;
  return (key: keyof typeof profileCopy) =>
    translations?.[`Shell/Profile/ui.${key}`] ?? profileCopy[key];
}
export function useFeedback() {
  const t = useProfileText();
  const [transport, setTransport] = useState('');
  const [notice, setNotice] = useState('');
  const failure = () => {
    setTransport(t('network_error'));
    return false;
  };
  return {
    transport,
    notice,
    setNotice,
    clear: () => {
      setTransport('');
      setNotice('');
    },
    failure,
  };
}
export function dateTime(value: string | null, timezone = 'UTC') {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.valueOf())
    ? '—'
    : new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: timezone,
      }).format(date);
}
