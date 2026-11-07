import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, TimePicker } from '@/components/ui';
import { Errors, Notice } from '@/features/Shell/Profile/shared';
import { useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import type { NotificationTimingDraft } from '@/types/Shell/Profile';

export function NotificationTimingForm({ draft, revision, active = false }: { draft: NotificationTimingDraft; revision: string; active?: boolean }) {
  const t = useProfileText();
  const feedback = useFeedback();
  const [transport, setTransport] = useState('');
  const form = useForm<NotificationTimingDraft>(draft);
  const save = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    setTransport('');
    const failed = () => { setTransport(t('timing_draft_uncertain')); return false; };
    form.put('/profile/notifications/timing', {
      preserveScroll: true,
      headers: { 'X-Brand-Revision': revision },
      onSuccess: () => feedback.setNotice(active ? 'Настройки сохранены для тестовой доставки.' : t('timing_draft_saved')),
      onNetworkError: failed,
      onHttpException: failed,
    });
  };
  return (
    <form onSubmit={save} className="mt-4 space-y-4">
      <p className="text-sm leading-6 text-(--color-ink-muted)">{active ? 'Тихие часы и сводка применяются к тестовой доставке email и Telegram.' : t('timing_draft_help')}</p>
      {active && <p className="text-sm leading-6 text-(--color-ink-muted)">Личное время сводки имеет приоритет над временем продавца для этого бренда. Если оно не выбрано, используется время продавца. Уведомления в кабинете приходят сразу.</p>}
      {feedback.notice && <Notice>{feedback.notice}</Notice>}
      <Errors errors={form.errors} transport={transport} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TimePicker label={t('quiet_start')} value={form.data.quiet_start ?? ''} onChange={(event) => form.setData('quiet_start', event.target.value || null)} error={form.errors.quiet_start} />
        <TimePicker label={t('quiet_end')} value={form.data.quiet_end ?? ''} onChange={(event) => form.setData('quiet_end', event.target.value || null)} error={form.errors.quiet_end} />
      </div>
      <label className="flex cursor-pointer items-center gap-2 text-sm">
        <input type="checkbox" checked={form.data.digest_time !== null} onChange={(event) => form.setData('digest_time', event.target.checked ? '09:00' : null)} />
        Сводка вместо каждого события: раз в день
      </label>
      <TimePicker label={t('digest_time')} value={form.data.digest_time ?? ''} onChange={(event) => form.setData('digest_time', event.target.value || null)} error={form.errors.digest_time} />
      <Button type="submit" variant="secondary" className="cursor-pointer" loading={form.processing} disabled={!form.isDirty}>{active ? 'Сохранить настройки' : t('timing_draft_save')}</Button>
    </form>
  );
}
