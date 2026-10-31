import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Item = { id: string; titles: string[]; created_at: string };

export default function DigestInbox({ items }: { items: Item[] }) {
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная' }, { label: 'Продавец' }, { label: 'Сводки' }]}>
      <Head title="Сводки уведомлений" />
      <h1 className="text-3xl font-bold">Сводки уведомлений</h1>
      <Link href="/seller/settings/notifications" className="mt-4 inline-block text-(--color-link)">Настройки уведомлений</Link>
      {items.length === 0 ? <p className="mt-6 text-(--color-ink-muted)">Пока нет доставленных сводок.</p> : (
        <ul className="mt-6 space-y-4">{items.map((item) => <li key={item.id} className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"><time className="text-sm text-(--color-ink-muted)">{item.created_at}</time><ul className="mt-3 list-disc space-y-2 pl-5">{item.titles.map((title, index) => <li key={index}>{title}</li>)}</ul></li>)}</ul>
      )}
    </CabinetLayout>
  );
}
