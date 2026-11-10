import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, TimePicker } from '@/components/ui';
import { Errors, Notice } from '@/features/Shell/Profile/shared';
import { useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import type { NotificationTimingDraft } from '@/types/Shell/Profile';

/** Personal quiet hours and daily digest; `active` = the values are applied to email/Telegram delivery. */
export function TimingDraftForm({ draft, revision, active = false }: { draft: NotificationTimingDraft; revision: string; active?: boolean }) {
  const t = useProfileText();
  const feedback = useFeedback();
  const [transport, setTransport] = useState('');
  const form = useForm<NotificationTimingDraft>(draft);
  const dirty = (['quiet_start', 'quiet_end', 'digest_time'] as const).some((key) => (form.data[key] ?? null) !== (draft[key] ?? null));
  const digestOn = form.data.digest_time !== null;
  const save = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    setTransport('');
    const failed = () => { setTransport(t('timing_draft_uncertain')); return false; };
    form.put('/profile/notifications/timing', {
      preserveScroll: true,
      headers: { 'X-Brand-Revision': revision },
      onSuccess: () => feedback.setNotice(active ? 'Настройки тихих часов и сводки сохранены.' : t('timing_draft_saved')),
      onNetworkError: failed,
      onHttpException: failed,
    });
  };
  return (
    <form onSubmit={save} className="mt-4 space-y-4">
      <p className="text-sm leading-6 text-(--color-ink-muted)">
        {active ? 'В тихие часы письма и сообщения в Telegram откладываются до их окончания. Уведомления в кабинете приходят сразу.' : t('timing_draft_help')}
      </p>
      {active && <p className="text-sm leading-6 text-(--color-ink-muted)">Личное время сводки имеет приоритет над временем продавца для этого бренда. Если оно не выбрано, используется время продавца.</p>}
      {feedback.notice && <Notice>{feedback.notice}</Notice>}
      <Errors errors={form.errors} transport={transport} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TimePicker label={t('quiet_start')} value={form.data.quiet_start ?? ''} onChange={(event) => form.setData('quiet_start', event.target.value || null)} error={form.errors.quiet_start} />
        <TimePicker label={t('quiet_end')} value={form.data.quiet_end ?? ''} onChange={(event) => form.setData('quiet_end', event.target.value || null)} error={form.errors.quiet_end} />
      </div>
      {active && (
        <label className="flex cursor-pointer items-start gap-3 border-t border-(--color-border) pt-4 text-sm">
          <input
            type="checkbox"
            aria-describedby="timing-digest-help"
            checked={digestOn}
            onChange={(event) => form.setData('digest_time', event.target.checked ? '09:00' : null)}
            className="mt-0.5 h-5 w-5 shrink-0 cursor-pointer accent-(--color-link)"
          />
          <span>
            <span className="font-semibold">Сводка вместо каждого события: раз в день{digestOn ? ` в ${form.data.digest_time}` : ''}</span>
            <span id="timing-digest-help" className="mt-1 block text-(--color-ink-muted)">Вместо мгновенных писем и сообщений в Telegram вы получите одну сводку за день по каждому каналу.</span>
          </span>
        </label>
      )}
      {(!active || digestOn) && (
        <TimePicker label={t('digest_time')} value={form.data.digest_time ?? ''} onChange={(event) => form.setData('digest_time', event.target.value || null)} error={form.errors.digest_time} />
      )}
      <Button type="submit" variant="secondary" className="cursor-pointer" loading={form.processing} disabled={!dirty}>
        {active ? 'Сохранить настройки' : t('timing_draft_save')}
      </Button>
    </form>
  );
}
