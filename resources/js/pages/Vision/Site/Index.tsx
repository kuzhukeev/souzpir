import { Head, Link, router } from '@inertiajs/react';
import { AuditForm, BotAccess, Criteria, Pages } from '@/features/Vision/Site/AuditDetails';
import { Actions } from '@/features/Vision/Site/Actions';
import { useEffect, type ReactNode } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { Audit, Criterion, Props } from '@/types/Vision/Site';

const card =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)';
const secondary =
  'inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) hover:bg-(--color-surface-muted) disabled:cursor-not-allowed disabled:opacity-60';
const sections = [
  ['overview', 'Обзор', '/vision/site'],
  ['criteria', 'Критерии', '/vision/site/criteria'],
  ['bots', 'Доступ ИИ-ботов', '/vision/site/bots'],
  ['pages', 'Страницы', '/vision/site/pages'],
  ['actions', 'Действия', '/vision/actions'],
  ['history', 'История', '/vision/actions/history'],
] as const;
function Empty({ children }: { children: ReactNode }) {
  return <div className={`${card} text-(--color-ink-muted)`}>{children}</div>;
}
function exactScore(audit: Audit | null | undefined): number | null {
  const raw = audit?.readiness;
  if (typeof raw === 'number' && Number.isFinite(raw)) return raw;
  if (raw && typeof raw === 'object' && 'lower' in raw && 'upper' in raw && typeof raw.lower === 'number' && raw.lower === raw.upper) return raw.lower;
  return null;
}
export function Score({ audit, auditDate, previousAudit }: { audit: Audit; auditDate?: string | null; previousAudit?: Audit | null }) {
  const raw = audit.readiness;
  const bounds =
    raw &&
    typeof raw === 'object' &&
    'lower' in raw &&
    'upper' in raw &&
    typeof raw.lower === 'number' &&
    typeof raw.upper === 'number'
      ? raw
      : null;
  const score =
    typeof raw === 'number'
      ? String(raw)
      : bounds
        ? bounds.lower === bounds.upper
          ? String(bounds.lower)
          : `${bounds.lower}–${bounds.upper}`
        : '—';
  const coverage =
    bounds && 'coverage' in bounds && typeof bounds.coverage === 'number' ? bounds.coverage : null;
  const value = exactScore(audit);
  const previous = exactScore(previousAudit);
  const sameSite = previousAudit?.url && previousAudit.url === audit.url;
  const delta = value !== null && previous !== null && sameSite ? Math.round((value - previous) * 100) / 100 : null;
  const progress = value === null ? 0 : Math.max(0, Math.min(100, value));
  return (
    <div className={card}>
      <div className="flex flex-wrap items-center gap-6">
        <div className="relative grid size-36 place-items-center rounded-full">
          <svg aria-hidden="true" viewBox="0 0 100 100" className="absolute inset-0 size-full -rotate-90"><circle cx="50" cy="50" r="44" fill="none" stroke="var(--color-border)" strokeWidth="8"/><circle cx="50" cy="50" r="44" fill="none" stroke="var(--color-action)" strokeWidth="8" pathLength="100" strokeDasharray={`${progress} 100`}/></svg>
          <span className="text-center">
            <strong className="block text-3xl">{score}</strong>
            <span className="text-sm text-(--color-ink-muted)">из 100</span>
          </span>
        </div>
        <div>
          <h2 className="text-xl font-semibold">Готовность к ИИ-поиску</h2>
          {auditDate && <p className="mt-2 text-sm text-(--color-ink-muted)">Дата проверки: {new Intl.DateTimeFormat('ru-RU', {dateStyle:'medium'}).format(new Date(auditDate))}</p>}
          <p className="mt-2 text-sm text-(--color-ink-muted)">{delta === null ? 'Изменение пока недоступно' : `Изменение: ${delta > 0 ? '+' : ''}${delta} п.п.`}</p>
          <p className="mt-2 break-all text-(--color-ink-muted)">{audit.final_url || audit.url}</p>
          {coverage !== null && (
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Проверено критериев: {coverage}%
            </p>
          )}
          <p className="mt-2 text-sm text-(--color-ink-muted)">
            Проверено страниц: {audit.crawled_pages ?? audit.pages?.length ?? 0}
          </p>
          {audit.scope && <p className="mt-2 text-sm text-(--color-ink-muted)">{audit.scope}</p>}
        </div>
      </div>
    </div>
  );
}
export function Groups({ criteria }: { criteria: Criterion[] }) {
  const groups = new Map<string, Criterion[]>();
  criteria.forEach((item) =>
    groups.set(item.group || 'Другие критерии', [
      ...(groups.get(item.group || 'Другие критерии') || []),
      item,
    ]),
  );
  if (!groups.size) return null;
  return (
    <section className={card}>
      <h2 className="text-xl font-semibold">По группам критериев</h2>
      <div className="mt-4 space-y-4">
        {Array.from(groups, ([group, items]) => {
          const available = items.filter((item) => !item.pending);
          const checked = available.filter(
            (item) => item.status && item.status !== 'unknown',
          ).length;
          return (
            <div key={group}>
              <div className="mb-2 flex flex-wrap justify-between gap-2">
                <span>{group}</span>
                <span className="text-sm text-(--color-ink-muted)">
                  {available.length ? `Проверено ${checked} из ${available.length}` : 'Скоро'}
                </span>
              </div>
              {available.length > 0 && (
                <progress
                  aria-label={`Проверено критериев: ${group}`}
                  className="h-3 w-full accent-(--color-action)"
                  value={checked}
                  max={available.length}
                />
              )}
            </div>
          );
        })}
      </div>
    </section>
  );
}
export function AuditProgress({progress}: {progress?: {stage:string} | null}) {
  const stage = progress?.stage;
  useEffect(() => {
    if (!['queued','inspecting','saving'].includes(stage ?? '')) return;
    const timer = window.setInterval(() => router.reload({only:['auditProgress','audit','auditDate','previousAudit']}), 2000);
    return () => window.clearInterval(timer);
  }, [stage]);
  if (!stage) return null;
  const phases = [{key:'queued',label:'Ожидание'}, {key:'inspecting',label:'Проверка страниц и ботов'}, {key:'saving',label:'Сохранение результатов'}];
  const active = stage === 'complete' ? phases.length : phases.findIndex(item => item.key === stage);
  return <section className={card} aria-live="polite"><h2 className="text-lg font-semibold">Проверка сайта</h2><p className="mt-2 text-sm">{({queued:'Ожидает очереди',inspecting:'Проверяем страницы и доступ ботов',saving:'Сохраняем результаты',complete:'Проверка завершена',failed:'Не удалось проверить сайт. Повторите попытку.'} as Record<string,string>)[stage] ?? 'Состояние недоступно'}</p>{active >= 0 && <><progress className="mt-3 w-full accent-(--color-action)" aria-label="Проверка сайта" max={phases.length} value={active} /><ol className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">{phases.map((phase,index)=><li key={phase.key}>{phase.label} · {index < active ? 'готово' : index === active ? 'выполняется' : 'ожидает'}</li>)}</ol></>}</section>;
}
export default function Index({
  section,
  audit,
  actions = [],
  history = [],
  achievements = [],
  comparison = null,
  canManage,
  members = [],
  auditDate = null,
  previousAudit = null,
  auditProgress = null,
}: Props & { auditDate?: string | null; previousAudit?: Audit | null; auditProgress?: {stage:string} | null }) {
  const titles: Record<Props['section'], string> = {
    overview: 'Готовность сайта к ИИ-поиску',
    criteria: 'Критерии и как исправить',
    bots: 'Доступ ИИ-ботов',
    pages: 'Проверенные страницы',
    actions: 'Текущие три',
    history: 'История и эффект',
  };
  return (
    <CabinetLayout
      brandSwitchReady
      breadcrumbs={[
        { label: 'Видение' },
        { label: section === 'actions' || section === 'history' ? 'Действия' : 'Сайт' },
      ]}
    >
      <Head title={titles[section]} />
      <div className="mx-auto max-w-[1440px] space-y-6">
        <header>
          <h1 className="text-3xl font-bold leading-tight">{titles[section]}</h1>
          <p className="mt-2 text-(--color-ink-muted)">
            {section === 'actions'
              ? 'Сосредоточьтесь на действиях для развития вашего бизнеса'
              : section === 'history'
                ? 'Выполненные действия'
                : 'Проверка доступности и содержания сайта'}
          </p>
        </header>
        <nav aria-label="Разделы сайта и действий" className="flex flex-wrap gap-2">
          {sections.map(([key, label, href]) => (
            <Link
              key={key}
              href={href}
              aria-current={section === key ? 'page' : undefined}
              className={`${secondary} ${section === key ? 'border-(--color-action) font-bold' : ''}`}
            >
              {label}
            </Link>
          ))}
        </nav>
        {section === 'overview' && (
          <div className="space-y-4">
            {audit ? (
              <>
                <Score audit={audit} auditDate={auditDate} previousAudit={previousAudit} />
                <Groups criteria={audit.criteria || []} />
              </>
            ) : (
              <Empty>Проверок сайта пока нет.</Empty>
            )}
            {canManage && <AuditForm />}
            <AuditProgress progress={auditProgress} />
            {audit?.issues?.length ? (
              <section className={card}>
                <h2 className="text-lg font-semibold">Замечания</h2>
                <ul className="mt-3 list-disc space-y-1 pl-5">
                  {audit.issues.map((issue, index) => (
                    <li key={`${issue}-${index}`}>{issue}</li>
                  ))}
                </ul>
              </section>
            ) : null}
          </div>
        )}
        {section === 'criteria' &&
          (audit ? (
            <Criteria criteria={audit.criteria || []} canManage={canManage} />
          ) : (
            <Empty>Сначала проверьте сайт.</Empty>
          ))}
        {section === 'bots' &&
          (audit ? <BotAccess audit={audit} /> : <Empty>Сначала проверьте сайт.</Empty>)}
        {section === 'pages' &&
          (audit ? <Pages audit={audit} /> : <Empty>Сначала проверьте сайт.</Empty>)}
        {section === 'actions' && (
          <Actions actions={actions} canManage={canManage} history={false} members={members} />
        )}
        {section === 'history' && (
          <Actions
            actions={history}
            canManage={canManage}
            history
            members={members}
            achievements={achievements}
            comparison={comparison}
          />
        )}
      </div>
    </CabinetLayout>
  );
}
