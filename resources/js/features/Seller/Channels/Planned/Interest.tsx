import { useState } from 'react';
import { Button } from '@/components/ui';
import { plannedRequest } from './request';
import type { PlannedItem } from '@/types/Seller/Channels/Planned';

export function Interest({ item, canManage, available, brandId }: { item: PlannedItem; canManage: boolean; available: boolean; brandId: string }) {
  const [result, setResult] = useState<{ item: PlannedItem; interested: boolean; error: string }>({ item, interested: item.interested, error: '' });
  const [busy, setBusy] = useState(false);
  // Never carry a locally saved status across a brand switch with new server props.
  const interested = result.item === item ? result.interested : item.interested;
  const error = result.item === item ? result.error : '';

  async function save() {
    setBusy(true);
    setResult({ item, interested, error: '' });
    try {
      await plannedRequest('/seller/planned/interests', 'POST', { integration: item.key }, brandId);
      setResult({ item, interested: true, error: '' });
    } catch (failure) {
      setResult({ item, interested, error: failure instanceof Error ? failure.message : 'Не удалось сохранить. Попробуйте ещё раз.' });
    } finally {
      setBusy(false);
    }
  }

  if (interested) return <p role="status" className="text-sm text-(--color-ink-muted)">Интерес сохранён. Уведомления о запуске пока не отправляются.</p>;
  return <div className="space-y-2">
    <Button onClick={() => void save()} disabled={!canManage || !available} loading={busy}>Сообщить, когда появится</Button>
    {!available && <p className="text-sm text-(--color-ink-muted)">Сохранение интереса пока недоступно.</p>}
    {available && !canManage && <p className="text-sm text-(--color-ink-muted)">Оставить заявку может владелец или администратор.</p>}
    {error && <p role="alert" className="text-sm text-(--color-danger-ink)">{error}</p>}
  </div>;
}
