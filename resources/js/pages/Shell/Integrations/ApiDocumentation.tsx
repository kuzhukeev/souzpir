import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Page, panel, focus } from '@/features/Shell/Organizations/Shared';

const endpoints = [
  ['GET', '/api/v1/brand', 'read', 'Бренд, к которому привязан ключ'],
  ['GET', '/api/v1/seller/conversations', 'read', 'Список диалогов'],
  ['GET', '/api/v1/seller/conversations/{conversation}', 'read', 'Диалог по идентификатору'],
  ['GET', '/api/v1/seller/leads', 'leads', 'Список лидов'],
  ['GET', '/api/v1/seller/leads/{lead}', 'leads', 'Лид по идентификатору'],
  ['POST', '/api/v1/leads', 'leads', 'Передача лида в API-канал'],
  ['POST', '/api/v1/turn', 'turn', 'Ход диалога через API-канал'],
];

export default function ApiDocumentation() {
  return <><Head title="Документация API" /><Page title="Документация API" subtitle="Доступ к данным текущего бренда">
    <Link href="/integrations/api" className={'mb-5 inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ' + focus}><ArrowLeft size={17} aria-hidden="true" /> API-ключи и вебхуки</Link>
    <section className={panel}><h2 className="text-xl font-bold">Подключение</h2><p className="mt-2 text-sm">Создайте ключ на странице «API-ключи и вебхуки» и сохраните его при первом показе. Передавайте ключ в заголовке <code>Authorization: Bearer &lt;ключ&gt;</code> по HTTPS. Ключ действует только для бренда, где он создан.</p><p className="mt-3 text-sm">Для проверки используйте <code>GET /api/v1/brand</code>. При ошибке аутентификации возвращается 401, при нехватке права — 403, при чужом бренде или объекте — 404.</p></section>
    <section className={`${panel} mt-5`}><h2 className="text-xl font-bold">Доступные методы</h2><div className="mt-3 overflow-x-auto"><table className="w-full min-w-[680px] text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">Метод</th><th className="p-3">Путь</th><th className="p-3">Право</th><th className="p-3">Назначение</th></tr></thead><tbody>{endpoints.map(([method, path, ability, description]) => <tr key={`${method}${path}`} className="border-b border-(--color-border)"><td className="p-3 font-semibold">{method}</td><td className="p-3 font-mono">{path}</td><td className="p-3">{ability}</td><td className="p-3">{description}</td></tr>)}</tbody></table></div></section>
    <section className={`${panel} mt-5`}><h2 className="text-xl font-bold">Диалоги и лиды</h2><p className="mt-2 text-sm">Права «Ход диалога» и «Лиды» выбираются при создании ключа. Доступность запросов зависит от настроек соответствующего модуля и API-канала.</p></section>
    <section className={`${panel} mt-5`}><h2 className="text-xl font-bold">Вебхуки</h2><p className="mt-2 text-sm">Настройка событий и проверка без отправки доступны на странице API-ключей. Реальная внешняя доставка сейчас выключена; тест не обращается к указанному адресу.</p></section>
  </Page></>;
}
