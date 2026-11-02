import { useState } from 'react';
import { router } from '@inertiajs/react';
import * as Select from '@radix-ui/react-select';
import { Check, ChevronDown } from 'lucide-react';
import type { BrandData, BrandSummaryData } from '@/types/generated/data';
import { beginProfileContextChange, finishProfileContextChange } from '@/features/Shell/Profile/contextFence';

const retryStorageKey = 'souzpir.profile-brand-switch.retry';
type Retry = { id: string; key: string; from: string };

function savedRetry(): Retry | null {
  try {
    const value = JSON.parse(localStorage.getItem(retryStorageKey) ?? 'null');
    if (typeof value?.id === 'string' && typeof value?.key === 'string' && typeof value?.from === 'string') return value;
  } catch { /* An invalid or unavailable store cannot authorize a retry. */ }
  return null;
}

// Enabled only after the dashboard integration supplies a safe post-switch destination.
export function BrandSwitch({ brand, brands, ready = false }: { brand: BrandData | null; brands: BrandSummaryData[]; ready?: boolean }) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState<Retry | null>(savedRetry);
  const retryHere = retry && (retry.from === brand?.id || retry.id === brand?.id) && brands.some((item) => item.id === retry.id) ? retry : null;
  if (brands.length < 2) return null;
  const select = (id: string) => {
    if (!ready || pending || id === brand?.id) return;
    setError('');
    if (retryHere) { setError('Сначала завершите прежнее переключение.'); return; }
    const contextChange = beginProfileContextChange();
    if (!contextChange) { setError('Не удалось подтвердить сессию. Обновите страницу.'); return; }
    const saved = { id, key: contextChange, from: brand?.id ?? '' };
    try { localStorage.setItem(retryStorageKey, JSON.stringify(saved)); }
    catch { finishProfileContextChange(contextChange); setError('Не удалось сохранить повтор. Обновите страницу.'); return; }
    setRetry(saved);
    send(id, contextChange);
  };
  const send = (id: string, key: string) => {
    setError('');
    router.post('/brand/switch', { brand_id: id }, { headers: { 'X-Context-Change-Key': key }, preserveState: false, onStart: () => setPending(true), onSuccess: () => { try { localStorage.removeItem(retryStorageKey); } catch { /* Keep private profile hidden. */ } finishProfileContextChange(key); setRetry(null); }, onFinish: () => setPending(false), onError: () => setError('Не удалось переключить бренд'), onNetworkError: () => { setError('Не удалось связаться с сервером.'); return false; }, onHttpException: () => { setError('Не удалось переключить бренд.'); return false; } });
  };
  if (!ready || !brand) return <span aria-disabled="true" title="Переключение бренда появится вместе с главной страницей" className="max-w-48 break-words rounded-lg bg-(--color-surface-muted) px-3 py-2 text-sm text-(--color-ink-muted)">{brand?.name ?? 'Бренд не выбран'} · Скоро</span>;
  return <div><Select.Root value={brand.id} onValueChange={select} disabled={pending || !!retryHere}><Select.Trigger aria-label="Выбрать бренд" className="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-wait"><Select.Value /><Select.Icon><ChevronDown size={16} /></Select.Icon></Select.Trigger><Select.Portal><Select.Content position="popper" sideOffset={6} className="z-50 min-w-[var(--radix-select-trigger-width)] rounded-xl border border-(--color-border) bg-(--color-surface) p-1.5 shadow-xl"><Select.Viewport>{brands.map((item) => <Select.Item value={item.id} key={item.id} className="relative flex min-h-11 cursor-pointer items-center rounded-lg px-3 pr-9 text-sm outline-none data-[highlighted]:bg-(--color-surface-muted)"><Select.ItemText>{item.name}</Select.ItemText><Select.ItemIndicator className="absolute right-3"><Check size={16} /></Select.ItemIndicator></Select.Item>)}</Select.Viewport></Select.Content></Select.Portal></Select.Root>{(error || retryHere) && <p role="alert" className="text-sm text-(--color-danger-ink)">{error || 'Переключение бренда требует подтверждения.'} {retryHere && <button type="button" disabled={pending} className="cursor-pointer underline" onClick={() => send(retryHere.id,retryHere.key)}>Повторить</button>}</p>}</div>;
}
