import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, TimePicker } from '@/components/ui';
import { Errors, Notice } from '@/features/Shell/Profile/shared';
import { useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import type { NotificationTimingDraft } from '@/types/Shell/Profile';

export function TimingDraftForm({ draft, revision }: { draft: NotificationTimingDraft; revision: string }) {
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
      onSuccess: () => feedback.setNotice(t('timing_draft_saved')),
      onNetworkError: failed,
      onHttpException: failed,
    });
  };
  return (
    <form onSubmit={save} className="mt-4 space-y-4">
      <p className="text-sm leading-6 text-(--color-ink-muted)">{t('timing_draft_help')}</p>
      {feedback.notice && <Notice>{feedback.notice}</Notice>}
      <Errors errors={form.errors} transport={transport} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TimePicker label={t('quiet_start')} value={form.data.quiet_start ?? ''} onChange={(event) => form.setData('quiet_start', event.target.value || null)} error={form.errors.quiet_start} />
        <TimePicker label={t('quiet_end')} value={form.data.quiet_end ?? ''} onChange={(event) => form.setData('quiet_end', event.target.value || null)} error={form.errors.quiet_end} />
      </div>
      <TimePicker label={t('digest_time')} value={form.data.digest_time ?? ''} onChange={(event) => form.setData('digest_time', event.target.value || null)} error={form.errors.digest_time} />
      <Button type="submit" variant="secondary" className="cursor-pointer" loading={form.processing}>{t('timing_draft_save')}</Button>
    </form>
  );
}
