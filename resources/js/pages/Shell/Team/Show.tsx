import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { SharedBrandModules } from '@/features/Shell/Team/SharedBrandModules';
import { EditMember } from '@/features/Shell/Team/EditMember';
import type { TeamMemberDetail } from '@/types/Shell/Team/members';

const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6';

export default function Show({
  member,
  activityUrl,
  activity = null,
  avatarUrl = null,
  notificationSettingsUrl = null,
  reportSettingsUrl = null,
  editAccess,
  dialogCount = null,
  receives = {},
  transferRecipients = [],
}: {
  member: TeamMemberDetail;
  activityUrl: string | null;
  activity?: { id: string; label: string; created_at: string }[] | null;
  avatarUrl?: string | null;
  notificationSettingsUrl?: string | null;
  reportSettingsUrl?: string | null;
  dialogCount?: number | null;
  receives?: Record<string, string[]>;
  transferRecipients?: {id:string;name:string}[];
  editAccess?: {
    brands: { id: string; name: string }[];
    allBrands: boolean;
    memberAllBrands: boolean;
    revision: string;
  } | null;
}) {
  const transfer = useForm({recipient_id:''});
  const name = member.name?.trim() || 'Имя недоступно';
  const initials = (member.name?.trim().split(/\s+/).slice(0, 2).map(part => part[0]?.toLocaleUpperCase('ru-RU')).join('') || '?');
  const status =
    member.status === 'active'
      ? 'Активен'
      : member.status === 'disabled'
        ? 'Отключён'
        : 'Статус недоступен';
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная', href: '/dashboard' },
        { label: 'Команда', href: '/team' },
        { label: name },
      ]}
    >
      <Head title={`${name} — Команда`} />
      <div className="mx-auto max-w-[1500px]">
        <Link
          href="/team"
          className="mb-5 inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
        >
          <ArrowLeft size={18} aria-hidden="true" /> К участникам
        </Link>
        <header className="mb-6 flex flex-wrap items-center gap-4">
          <span
            aria-hidden="true"
            className="grid size-16 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-info-ink)"
          >
            {avatarUrl ? <img src={avatarUrl} alt="" className="size-full rounded-full object-cover" /> : <span className="text-xl font-semibold">{initials}</span>}
          </span>
          <div className="min-w-0">
            <h1 className="break-words text-3xl font-bold leading-tight">{name}</h1>
            <p className="mt-2 break-all text-sm text-(--color-ink-muted)">
              {member.email || 'Адрес недоступен'}
            </p>
          </div>
          <span
            className={`rounded-full px-3 py-1 text-sm font-medium ${member.status === 'active' ? 'bg-(--color-success-soft) text-(--color-success-ink)' : 'bg-(--color-surface-muted) text-(--color-ink-muted)'}`}
          >
            {status}
          </span>
        </header>
        <div className="grid gap-4 lg:grid-cols-2">
          <section className={panel} aria-labelledby="access-title">
            <h2 id="access-title" className="text-xl font-bold">
              Роль и доступ
            </h2>
            <dl className="mt-5 space-y-5 text-sm">
              <div>
                <dt className="text-(--color-ink-muted)">Роль</dt>
                <dd className="mt-1 font-semibold">{member.role_label || 'Роль не определена'}</dd>
              </div>
              <div>
                <dt className="text-(--color-ink-muted)">Доступные бренды</dt>
                <dd className="mt-2">
                  {member.brands.length ? (
                    <ul className="flex flex-wrap gap-2">
                      {member.brands.map((brand) => (
                        <li
                          key={brand.id}
                          className="max-w-full break-words rounded-full bg-(--color-success-soft) px-3 py-1 text-(--color-success-ink)"
                        >
                          {brand.name}
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <span>Нет общих доступных брендов</span>
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-(--color-ink-muted)">Модули общих брендов</dt>
                <dd className="mt-2">
                  <SharedBrandModules brands={member.brands} />
                  <p className="mt-3 text-sm text-(--color-ink-muted)">Отдельные разрешения на модули появятся позже.</p>
                  <label className="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" disabled /> Продавец</label>
                  <label className="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" disabled /> Видение</label>
                </dd>
              </div>
            </dl>
            <p className="mt-5 text-sm text-(--color-ink-muted)">
              Показаны только общие доступные бренды и их включённые модули. Действия участника
              зависят от его роли и статуса.
            </p>
            {editAccess && <EditMember member={member} access={editAccess} />}
          </section>
          <section className={panel} aria-labelledby="contacts-title">
            <h2 id="contacts-title" className="text-xl font-bold">
              Контакты
            </h2>
            <dl className="mt-5 space-y-5 text-sm">
              <div>
                <dt className="text-(--color-ink-muted)">Почта</dt>
                <dd className="mt-1 break-all">{member.email || 'Адрес недоступен'}</dd>
              </div>
              <div>
                <dt className="text-(--color-ink-muted)">Телефон</dt>
                <dd className="mt-1 break-words">{member.phone?.trim() || 'Не указан'}</dd>
              </div>
            </dl>
          </section>
        </div>
        {dialogCount !== null && <section className={`${panel} mt-4`}><h2 className="text-xl font-bold">Диалоги в работе</h2><p className="mt-3 text-sm">Назначено активных диалогов: {dialogCount}</p>{transferRecipients.length > 0 && <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={event=>{event.preventDefault();transfer.post(`/team/${member.id}/transfer-dialogs`,{preserveScroll:true,onSuccess:()=>transfer.reset()});}}><label className="text-sm">Коллега<select required value={transfer.data.recipient_id} onChange={event=>transfer.setData('recipient_id',event.target.value)} className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="">Выберите коллегу</option>{transferRecipients.map(person=><option key={person.id} value={person.id}>{person.name}</option>)}</select></label><button disabled={transfer.processing || !dialogCount} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm disabled:opacity-50">Передать все коллеге</button>{transfer.errors.recipient_id && <p role="alert" className="text-sm">{transfer.errors.recipient_id}</p>}</form>}</section>}
        {(Object.keys(receives).length > 0 || notificationSettingsUrl || reportSettingsUrl) && <section className={`${panel} mt-4`}><h2 className="text-xl font-bold">Получает</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Каналы для текущего бренда</p><ul className="mt-3 space-y-2 text-sm">{Object.entries(receives).map(([event,channels])=><li key={event}><strong>{event === 'vision.measurement.completed' ? 'Отчёт после замера' : 'Диалог ждёт менеджера'}</strong>: {channels.length ? channels.map(channel=>({cabinet:'В кабинете',email:'Почта',telegram:'Telegram'}[channel] ?? channel)).join(', ') : 'Не получает'}</li>)}</ul><div className="mt-4 flex flex-wrap gap-4 text-sm">{reportSettingsUrl && <Link className="cursor-pointer text-(--color-link)" href={reportSettingsUrl}>Настроить отчёты</Link>}{notificationSettingsUrl ? <Link className="cursor-pointer text-(--color-link)" href={notificationSettingsUrl}>Настроить уведомления</Link> : <span className="text-(--color-ink-muted)">Настройки уведомлений продавца недоступны.</span>}</div></section>}
        {activityUrl && (
          <section className={`${panel} mt-4`} aria-labelledby="activity-title">
            <h2 id="activity-title" className="text-xl font-bold">
              Активность
            </h2>
            <p className="mt-3 text-sm text-(--color-ink-muted)">
              Журнал показывает действия только текущего бренда и общие действия организации.
            </p>
            {activity && (activity.length ? <ul className="mt-3 divide-y divide-(--color-border)">{activity.map((item) => <li key={item.id} className="py-2 text-sm"><span>{item.label}</span><time className="ml-3 text-(--color-ink-muted)">{new Date(item.created_at).toLocaleString('ru-RU')}</time></li>)}</ul> : <p className="mt-3 text-sm">Действий пока нет.</p>)}
            <Link
              href={activityUrl}
              className="mt-4 inline-flex min-h-11 cursor-pointer items-center rounded-lg text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
            >
              Посмотреть активность участника
            </Link>
          </section>
        )}
      </div>
    </CabinetLayout>
  );
}
