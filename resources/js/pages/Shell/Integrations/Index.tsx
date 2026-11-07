import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Bell, Cable, Globe, Mail, MessageCircle, Send } from 'lucide-react';
import { Page, panel, focus } from '@/features/Shell/Organizations/Shared';

type Connection = {
  id: string;
  title: string;
  description: string;
  status: string;
  href: string | null;
  connected_at: string | null;
  settings_restricted?: boolean;
};
type Props = { connections: Connection[] };
const statusLabels: Record<string, string> = {
  configured: 'Настроена',
  connected: 'Подключён',
  active: 'Подключён',
  disconnected: 'Не подключён',
  inactive: 'Не подключён',
  unconfigured: 'Не подключён',
  disabled: 'Выключен',
  planned: 'Скоро',
  pending: 'Ожидает подключения',
  error: 'Ошибка подключения',
  soon: 'Скоро',
};
const icons: Record<string, typeof Cable> = {
  telegram: Send,
  widget: Globe,
  price_email: Mail,
  notifications: Bell,
  whatsapp: MessageCircle,
  crm_webhook: Cable,
};
export default function Index({ connections }: Props) {
  return (
    <>
      <Head title="Подключения" />
      <Page title="Подключения" subtitle="Все каналы и интеграции бренда в одном месте">
        {connections.length === 0 ? (
          <p className={panel + ' text-sm text-(--color-ink-muted)'}>Подключений пока нет</p>
        ) : (
          <div className="grid gap-4 xl:grid-cols-2">
            {connections.map((item) => {
              const Icon = icons[item.id] ?? Cable;
              return (
                <article key={item.id} className={panel}>
                  <div className="flex flex-wrap items-start gap-4">
                    <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-(--color-info-soft) text-(--color-link)">
                      <Icon aria-hidden="true" size={24} />
                    </span>
                    <div className="min-w-0 flex-1">
                      <h2 className="break-words text-xl font-bold">{item.title}</h2>
                      <p className="mt-1 text-sm text-(--color-ink-muted)">
                        {statusLabels[item.status] ?? 'Статус неизвестен'}
                      </p>
                      {item.connected_at && (
                        <p className="mt-1 text-sm text-(--color-ink-muted)">
                          Подключён {new Date(item.connected_at).toLocaleDateString('ru-RU')}
                        </p>
                      )}
                    </div>
                    {item.href && item.status !== 'soon' && item.status !== 'planned' ? (
                      <Link
                        href={item.href}
                        className={
                          'inline-flex min-h-10 w-full cursor-pointer items-center justify-center gap-2 rounded-lg sm:w-auto bg-(--color-info-soft) px-4 text-sm font-semibold text-(--color-link) ' +
                          focus
                        }
                      >
                        Настроить <ArrowRight size={16} aria-hidden="true" />
                      </Link>
                    ) : item.status === 'soon' || item.status === 'planned' ? (
                      <span className="rounded-lg bg-(--color-surface-muted) px-4 py-2 text-sm text-(--color-ink-muted)">
                        Скоро
                      </span>
                    ) : item.settings_restricted ? (
                      <span className="w-full rounded-lg bg-(--color-surface-muted) px-4 py-2 text-center text-sm text-(--color-ink-muted) sm:w-auto">
                        Нет прав на настройку
                      </span>
                    ) : null}
                  </div>
                  <p className="mt-5 border-t border-(--color-border) pt-4 text-sm leading-6 text-(--color-ink-muted)">
                    {item.description}
                  </p>
                </article>
              );
            })}
          </div>
        )}
      </Page>
    </>
  );
}
