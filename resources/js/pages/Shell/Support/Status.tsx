import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Activity,
  RefreshCw,
  Server,
  Database,
  Monitor,
  Send,
  ChartNoAxesCombined,
  Bot,
  ExternalLink,
  type LucideIcon,
} from 'lucide-react';
import { Button, EmptyState, Skeleton } from '@/components/ui';
import {
  SupportPage,
  ErrorSummary,
  DateValue,
  panel,
  focus,
} from '@/features/Shell/Support/Shared';
import { useSupportCopy } from '@/features/Shell/Support/copy';
import type { StatusData } from '@/types/Shell/Support';
const icons: Record<string, LucideIcon> = {
  cabinet: Server,
  database: Database,
  widget: Monitor,
  telegram: Send,
  vision: ChartNoAxesCombined,
};
export default function Status({ status }: { status: StatusData }) {
  const c = useSupportCopy();
  const [loading, setLoading] = useState(false);
  const [failure, setFailure] = useState({ message: '' });
  const refresh = () => {
    setFailure({ message: '' });
    router.reload({
      only: ['status'],
      onStart: () => setLoading(true),
      onFinish: () => setLoading(false),
      onError: () => setFailure({ message: c.loadFailed }),
      onNetworkError: () => {
        setFailure({ message: c.loadFailed });
        return false;
      },
      onHttpException: () => {
        setFailure({ message: c.loadFailed });
        return false;
      },
    });
  };
  const badge = (state: string) => {
    const good = state === 'operational';
    const warning = state === 'cooldown' || state === 'degraded';
    const label = good
      ? c.available
      : state === 'degraded'
        ? c.degraded
        : state === 'cooldown'
          ? c.cooldown
          : state === 'planned'
            ? c.planned
            : c.unknown;
    return (
      <span
        className={[
          'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm',
          good
            ? 'bg-(--color-success-soft) text-(--color-success-ink)'
            : warning
              ? 'bg-(--color-warning-soft) text-(--color-warning-ink)'
              : 'bg-(--color-surface-muted) text-(--color-ink-muted)',
        ].join(' ')}
      >
        <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-current" />
        {label}
      </span>
    );
  };
  return (
    <>
      <Head title={c.status} />
      <SupportPage
        active="status"
        title={c.status}
        subtitle={c.statusSubtitle}
        actions={
          <Button variant="secondary" loading={loading} onClick={refresh}>
            <RefreshCw size={18} />
            {c.refresh}
          </Button>
        }
      >
        <div className="mb-5 space-y-3">
          <ErrorSummary failure={failure} />
          <p className="text-sm text-(--color-ink-muted)">
            {c.checked}: <DateValue value={status.checked_at} />
          </p>
        </div>
        <div aria-busy={loading}>
          {loading ? (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {[1, 2, 3].map((item) => (
                <div key={item} className={[panel, 'p-6'].join(' ')}>
                  <Skeleton label={c.loading} />
                </div>
              ))}
            </div>
          ) : (
            <>
              <div className="grid items-stretch gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {status.components.map((component) => {
                  const Icon = icons[component.id] ?? Activity;
                  return (
                    <section
                      key={component.id}
                      data-component-id={component.id}
                      className={[panel, 'p-5'].join(' ')}
                    >
                      <div className="flex items-start gap-4">
                        <span className="grid size-12 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)">
                          <Icon size={25} />
                        </span>
                        <div className="min-w-0">
                          <h2 className="mb-3 break-words text-lg font-bold">{component.name}</h2>
                          {badge(component.status)}
                        </div>
                      </div>
                      <p className="mt-4 text-sm leading-6 text-(--color-ink-muted)">
                        {component.message}
                      </p>
                      {component.checked_at && (
                        <p className="mt-3 text-sm text-(--color-ink-muted)">
                          {c.checked}: <DateValue value={component.checked_at} />
                        </p>
                      )}
                    </section>
                  );
                })}
              </div>
              {!status.components.length && <EmptyState title={c.statusEmpty} />}
              <section className={[panel, 'mt-5 p-5 sm:p-6'].join(' ')}>
                <h2 className="flex items-center gap-3 text-xl font-bold">
                  <Bot size={25} className="text-(--color-link)" />
                  {c.providers}
                </h2>
                <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
                  {status.providers.message}
                </p>
                {status.providers.items.length ? (
                  <ul className="mt-4 divide-y divide-(--color-border)">
                    {status.providers.items.map((provider) => (
                      <li
                        key={provider.id}
                        data-provider-id={provider.id}
                        className="flex flex-wrap items-center justify-between gap-3 py-4"
                      >
                        <div className="min-w-0">
                          <h3 className="break-words text-base font-semibold">{provider.name}</h3>
                          {provider.cooldown_until && (
                            <p className="mt-2 text-sm text-(--color-ink-muted)">
                              {c.cooldownUntil}: <DateValue value={provider.cooldown_until} />
                            </p>
                          )}
                          {provider.checked_at && (
                            <p className="mt-1 text-sm text-(--color-ink-muted)">
                              {c.checked}: <DateValue value={provider.checked_at} />
                            </p>
                          )}
                        </div>
                        {badge(provider.status)}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-4 text-sm text-(--color-ink-muted)">{c.noProviders}</p>
                )}
              </section>
            </>
          )}
        </div>
        <section className={[panel, 'mt-5 p-5 sm:p-6'].join(' ')}>
          <h2 className="text-xl font-bold">{c.incidents}</h2>
          <EmptyState title={c.incidentsUnavailable} description={c.incidentsHint} />
        </section>
        <div className="mt-5 flex flex-wrap items-center justify-between gap-4">
          <p className="text-sm leading-6 text-(--color-ink-muted)">{c.healthHint}</p>
          <a
            href={status.health_url}
            target="_blank"
            rel="noopener noreferrer"
            className={[
              'inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-(--color-link)',
              focus,
            ].join(' ')}
          >
            {c.httpHealth}
            <ExternalLink size={17} />
          </a>
        </div>
      </SupportPage>
    </>
  );
}
