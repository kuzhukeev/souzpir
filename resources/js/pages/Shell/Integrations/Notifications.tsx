import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Mail, Send } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { Page, panel, focus } from '@/features/Shell/Organizations/Shared';

type Props = {
  telegram_configured: boolean;
  telegram_mask: string | null;
  report_emails: string[];
};
export default function Notifications({
  telegram_configured,
  telegram_mask,
  report_emails,
}: Props) {
  return (
    <>
      <Head title="Уведомления компании" />
      <Page title="Уведомления компании" subtitle="Каналы уведомлений для всей команды">
        <Link
          href="/integrations"
          className={
            'mb-5 inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ' +
            focus
          }
        >
          <ArrowLeft size={17} aria-hidden="true" /> Подключения
        </Link>
        <p
          role="status"
          className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)"
        >
          Настройка и доставка уведомлений компании пока недоступны.
        </p>
        <div className="grid gap-4 xl:grid-cols-2">
          <section className={panel}>
            <h2 className="flex items-center gap-2 text-xl font-bold">
              <Send size={22} aria-hidden="true" /> Telegram
            </h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              {telegram_configured
                ? `Чат настроен${telegram_mask ? `: ${telegram_mask}` : ''}`
                : 'Чат не настроен'}
            </p>
            <div className="mt-5">
              <Input label="ID чата Telegram" disabled value="" readOnly />
            </div>
          </section>
          <section className={panel}>
            <h2 className="flex items-center gap-2 text-xl font-bold">
              <Mail size={22} aria-hidden="true" /> Почта для отчётов
            </h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Адреса для отчётов и системных уведомлений
            </p>
            {report_emails.length === 0 ? (
              <p className="mt-4 text-sm text-(--color-ink-muted)">Адреса пока не добавлены</p>
            ) : (
              <ul className="mt-4 space-y-2 text-sm">
                {report_emails.map((email) => (
                  <li className="break-all" key={email}>
                    {email}
                  </li>
                ))}
              </ul>
            )}
            <div className="mt-4">
              <Input label="Адрес электронной почты" type="email" disabled value="" readOnly />
            </div>
          </section>
        </div>
        <div className="mt-5 flex justify-end">
          <Button disabled>Сохранить</Button>
        </div>
      </Page>
    </>
  );
}
