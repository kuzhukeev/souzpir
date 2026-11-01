import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

export default function Index() {
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная', href: '/dashboard' },
        { label: 'Интеграции', href: '/seller/integrations/planned' },
        { label: 'CRM-вебхук' },
      ]}
    >
      <Head title="CRM-вебхук" />
      <div className="mx-auto max-w-5xl">
        <Link
          href="/seller/integrations/planned"
          className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)"
        >
          ← К интеграциям
        </Link>
        <h1 className="mt-3 text-3xl font-bold">CRM-вебхук</h1>
        <p className="mt-3 text-sm text-(--color-ink-muted)">
          Передавайте сведения о контакте и передаче диалога в свою CRM.
        </p>
        <section
          className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 shadow-(--shadow-card)"
          aria-labelledby="crm-status"
        >
          <h2 id="crm-status" className="text-xl font-bold">
            Настройка временно недоступна
          </h2>
          <p className="mt-3 text-sm">
            Создание вебхука и отправка событий появятся после подключения безопасной доставки.
          </p>
          <h3 className="mt-6 font-semibold">События</h3>
          <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
            <li>Контакт получен</li>
            <li>Диалог передан менеджеру</li>
          </ul>
          <p className="mt-5 text-sm text-(--color-ink-muted)">
            Подключения amoCRM и Битрикс24 появятся позднее.
          </p>
        </section>
      </div>
    </CabinetLayout>
  );
}

