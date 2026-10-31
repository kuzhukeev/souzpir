import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Bell, Clock3, Mail, MessageSquare, Monitor, Send } from 'lucide-react';
import { Badge, Button, EmptyState, Modal, Select } from '@/components/ui';
import { Errors, Notice, ProfilePage, focus, panel } from '@/features/Shell/Profile/shared';
import { dateTime, useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import { requestKey } from '@/features/Shell/Profile/browser';
import { TimingDraftForm } from '@/features/Shell/Profile/TimingDraftForm';
import type { Channel, NotificationSettings } from '@/types/Shell/Profile';

const channels: Channel[] = ['cabinet', 'email', 'telegram'];
const icons = { cabinet: Monitor, email: Mail, telegram: Send };
export default function Notifications({
  notificationSettings: settings,
  status,
}: {
  notificationSettings: NotificationSettings;
  status?: string | null;
}) {
  const t = useProfileText();
  const form = useForm({
    changes: settings.events.flatMap((event) =>
      channels.map((channel) => ({ event: event.key, channel, enabled: event.channels[channel] })),
    ),
  });
  const telegram = useForm({});
  const testDelivery = useForm({ channel: 'cabinet' as Channel, request_key: '' });
  const feedback = useFeedback();
  const [disconnectOpen, setDisconnectOpen] = useState(false);
  const syncTelegram = (page: { props: Record<string, unknown> }) => {
    const fresh = page.props.notificationSettings as NotificationSettings;
    // Telegram mutations reset its saved channels; preserve unrelated unsaved choices.
    form.setData((previous) => ({
      changes: fresh.events.flatMap((event) =>
        channels.map((channel) => ({
          event: event.key,
          channel,
          enabled:
            channel === 'telegram'
              ? event.channels.telegram
              : (previous.changes.find(
                  (change) => change.event === event.key && change.channel === channel,
                )?.enabled ?? event.channels[channel]),
        })),
      ),
    }));
    form.clearErrors();
  };
  const toggle = (event: string, channel: Channel) =>
    form.setData(
      'changes',
      form.data.changes.map((change) =>
        change.event === event && change.channel === channel
          ? { ...change, enabled: !change.enabled }
          : change,
      ),
    );
  const save = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    form.put('/profile/notifications', {
      preserveScroll: true,
      onSuccess: () => feedback.setNotice(t('saved')),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const sendTest = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    testDelivery.transform((data) => ({ ...data, request_key: requestKey() }));
    testDelivery.post('/profile/notifications/test', {
      preserveScroll: true,
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const connect = () => {
    feedback.clear();
    telegram.post('/profile/notifications/telegram', {
      preserveScroll: true,
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const confirmTelegram = () => {
    feedback.clear();
    telegram.post('/profile/notifications/telegram/confirm', {
      preserveScroll: true,
      onSuccess: syncTelegram,
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const disconnect = () => {
    feedback.clear();
    telegram.delete('/profile/notifications/telegram', {
      preserveScroll: true,
      onSuccess: (page) => {
        syncTelegram(page);
        setDisconnectOpen(false);
      },
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  return (
    <ProfilePage active="notifications">
      <Notice>{feedback.notice || status}</Notice>
      <Errors
        errors={{ ...form.errors, ...telegram.errors, ...testDelivery.errors }}
        transport={feedback.transport}
      />
      <div className="mb-5 grid items-start gap-5 xl:grid-cols-2">
        <section className={panel}>
          <div className="flex items-center gap-3">
            <Clock3 className="text-(--color-link)" size={24} aria-hidden="true" />
            <h2 className="text-xl font-bold">{t('quiet_title')}</h2>
            <Badge tone="info">{t('soon')}</Badge>
          </div>
          <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
            {t('quiet_description')}
          </p>
          <p className="mt-2 text-sm">
            {t('timezone')}: <strong>{settings.timezone}</strong>
          </p>
          {settings.timingDraftEditable && settings.timingDraft && settings.timingRevision ? (
            <TimingDraftForm key={`${settings.timingRevision}:${JSON.stringify(settings.timingDraft)}`} draft={settings.timingDraft} revision={settings.timingRevision} />
          ) : (
            <p className="mt-3 text-sm text-(--color-ink-muted)">{t('timing_draft_unavailable')}</p>
          )}
          <form
            onSubmit={sendTest}
            className="mt-5 space-y-3 border-t border-(--color-border) pt-4"
          >
            <Select
              label={t('test_channel')}
              value={testDelivery.data.channel}
              onValueChange={(channel) => testDelivery.setData('channel', channel as Channel)}
              options={channels.map((channel) => ({
                value: channel,
                label: t(channel),
                disabled: !settings.testChannels[channel],
              }))}
            />
            <Button
              type="submit"
              variant="secondary"
              className="cursor-pointer"
              loading={testDelivery.processing}
              disabled={!settings.testChannels[testDelivery.data.channel]}
            >
              <Send size={17} aria-hidden="true" />
              {t('test_notification')}
            </Button>
            <p className="text-sm leading-6 text-(--color-ink-muted)">{t('test_help')}</p>
          </form>
          {settings.testNotifications.length > 0 && (
            <div className="mt-4 space-y-3 border-t border-(--color-border) pt-4">
              <h3 className="text-base font-semibold">{t('test_history')}</h3>
              <ul className="space-y-3">
                {settings.testNotifications.map((item) => (
                  <li key={item.id} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <strong>{t(item.channel)}</strong>
                      <Badge
                        tone={
                          item.status === 'sent'
                            ? 'success'
                            : item.status === 'uncertain'
                              ? 'warning'
                              : 'info'
                        }
                      >
                        {t(`test_${item.status}`)}
                      </Badge>
                    </div>
                    <p className="mt-2">{t('test_message')}</p>
                    <time
                      dateTime={item.created_at}
                      className="mt-1 block text-(--color-ink-muted)"
                    >
                      {dateTime(item.created_at, settings.timezone)}
                    </time>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
        <section className={panel}>
          <div className="flex flex-wrap items-center gap-3">
            <Send className="text-(--color-link)" size={24} aria-hidden="true" />
            <h2 className="text-xl font-bold">{t('telegram_title')}</h2>
            <Badge tone={settings.telegram.connected ? 'success' : 'warning'}>
              {t(settings.telegram.connected ? 'connected' : 'not_connected')}
            </Badge>
          </div>
          <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
            {t('telegram_description')}
          </p>
          {!settings.telegram.connected && (
            <p className="mt-2 text-sm text-(--color-ink-muted)">{t('telegram_connect_hint')}</p>
          )}
          {!settings.telegram.configured ? (
            <p className="mt-3 text-sm text-(--color-ink-muted)">{t('telegram_unavailable')}</p>
          ) : settings.telegram.pending ? (
            <div className="mt-4 space-y-3">
              <p className="font-semibold">{t('telegram_pending')}</p>
              <p className="break-words text-sm">{settings.telegram.pending_label}</p>
              <p className="text-sm leading-6 text-(--color-ink-muted)">
                {t('telegram_pending_hint')}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  className="cursor-pointer"
                  loading={telegram.processing}
                  onClick={confirmTelegram}
                >
                  {t('telegram_confirm')}
                </Button>
                <Button
                  variant="secondary"
                  className="cursor-pointer"
                  disabled={telegram.processing}
                  onClick={disconnect}
                >
                  {t('cancel')}
                </Button>
              </div>
            </div>
          ) : settings.telegram.connected ? (
            <Button
              variant="secondary"
              className="mt-4 cursor-pointer"
              onClick={() => setDisconnectOpen(true)}
            >
              {t('disconnect')}
            </Button>
          ) : (
            <div className="mt-4 space-y-3">
              {settings.telegram.link_available ? (
                <>
                  <p className="text-sm leading-6">{t('telegram_link_hint')}</p>
                  <a
                    href="/profile/notifications/telegram/link"
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-(--color-on-action) ${focus}`}
                  >
                    <Send size={17} aria-hidden="true" />
                    {t('open_telegram')}
                  </a>
                  <p className="text-sm text-(--color-ink-muted)">
                    {t('link_expires')} {dateTime(settings.telegram.expires_at, settings.timezone)}
                  </p>
                  <Button
                    variant="secondary"
                    className="cursor-pointer"
                    onClick={() =>
                      router.reload({
                        only: ['notificationSettings'],
                        onNetworkError: feedback.failure,
                        onHttpException: feedback.failure,
                      })
                    }
                  >
                    {t('refresh')}
                  </Button>
                </>
              ) : (
                <Button className="cursor-pointer" loading={telegram.processing} onClick={connect}>
                  {t('connect')}
                </Button>
              )}
            </div>
          )}
        </section>
      </div>
      <form onSubmit={save}>
        <section className={panel}>
          <div className="mb-5 flex items-start gap-3">
            <Bell className="mt-1 text-(--color-link)" size={24} aria-hidden="true" />
            <div>
              <h2 className="text-xl font-bold">{t('events_title')}</h2>
              <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
                {t('events_description')}
              </p>
            </div>
          </div>
          {settings.events.length === 0 ? (
            <EmptyState
              title={t('events_empty')}
              description={t('events_empty_description')}
              icon={<MessageSquare size={32} />}
            />
          ) : (
            <div>
              <div className="hidden grid-cols-[minmax(0,1fr)_repeat(3,minmax(92px,130px))] gap-3 border-b border-(--color-border) pb-3 text-sm font-semibold md:grid">
                <span>{t('event_label')}</span>
                {channels.map((channel) => {
                  const Icon = icons[channel];
                  return (
                    <span key={channel} className="flex items-center justify-center gap-2">
                      <Icon size={18} aria-hidden="true" />
                      {t(channel)}
                    </span>
                  );
                })}
              </div>
              {(['seller', 'vision', 'shell'] as const).map((group) => {
                const events = settings.events.filter((event) => event.group === group);
                return events.length ? (
                  <section
                    key={group}
                    aria-label={t(group)}
                    className="border-b border-(--color-border) py-4 last:border-0 last:pb-0"
                  >
                    <h3 className="mb-3 text-base font-bold">{t(group)}</h3>
                    <div className="space-y-2">
                      {events.map((event) => (
                        <div
                          key={event.key}
                          className="grid gap-3 rounded-xl bg-(--color-surface-muted)/40 p-3 md:grid-cols-[minmax(0,1fr)_repeat(3,minmax(92px,130px))] md:items-center md:bg-transparent md:px-0"
                        >
                          <div>
                            <p className="text-sm leading-6">{event.title}</p>
                            {!event.eligible && (
                              <p className="text-sm text-(--color-ink-muted)">
                                {t('event_unavailable')}
                              </p>
                            )}
                          </div>
                          <div className="grid grid-cols-3 gap-2 md:contents">
                            {channels.map((channel) => {
                              const checked =
                                form.data.changes.find(
                                  (change) =>
                                    change.event === event.key && change.channel === channel,
                                )?.enabled ?? false;
                              return (
                                <div key={channel} className="flex flex-col items-center gap-2">
                                  <span className="text-sm text-(--color-ink-muted) md:hidden">
                                    {t(channel)}
                                  </span>
                                  <button
                                    type="button"
                                    role="switch"
                                    aria-label={`${event.title}: ${t(channel)}`}
                                    aria-checked={checked}
                                    disabled={
                                      !event.eligible ||
                                      form.processing ||
                                      (channel === 'telegram' && !settings.telegram.connected)
                                    }
                                    onClick={() => toggle(event.key, channel)}
                                    className={`flex h-8 w-12 cursor-pointer items-center rounded-full p-1 transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${checked ? 'bg-(--color-action)' : 'bg-(--color-ink-muted)'} ${focus}`}
                                  >
                                    <span
                                      aria-hidden="true"
                                      className={`size-6 rounded-full bg-white shadow-sm transition-transform motion-reduce:transition-none ${checked ? 'translate-x-4' : ''}`}
                                    />
                                  </button>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      ))}
                    </div>
                  </section>
                ) : null;
              })}
            </div>
          )}
        </section>
        <div className="mt-5 flex justify-end">
          <Button
            type="submit"
            className="w-full cursor-pointer sm:w-auto"
            loading={form.processing}
            disabled={!settings.events.length}
          >
            {t('save')}
          </Button>
        </div>
      </form>
      <Modal
        open={disconnectOpen}
        title={t('telegram_disconnect_title')}
        onClose={() => !telegram.processing && setDisconnectOpen(false)}
      >
        <p className="mb-5 leading-6 text-(--color-ink-muted)">
          {t('telegram_disconnect_description')}
        </p>
        <Errors errors={telegram.errors} transport={feedback.transport} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            variant="secondary"
            className="cursor-pointer"
            onClick={() => setDisconnectOpen(false)}
            disabled={telegram.processing}
          >
            {t('cancel')}
          </Button>
          <Button
            variant="danger"
            className="cursor-pointer"
            loading={telegram.processing}
            onClick={disconnect}
          >
            {t('disconnect')}
          </Button>
        </div>
      </Modal>
    </ProfilePage>
  );
}
