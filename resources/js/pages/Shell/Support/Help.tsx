import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
  BookOpen,
  ChartNoAxesCombined,
  MessageCircle,
  Monitor,
  Users,
  CreditCard,
  ExternalLink,
  Search,
  FileText,
  Info,
  type LucideIcon,
} from 'lucide-react';
import { Input, Button, EmptyState } from '@/components/ui';
import { SupportPage, panel, focus } from '@/features/Shell/Support/Shared';
import { useSupportCopy } from '@/features/Shell/Support/copy';
import type { HelpData } from '@/types/Shell/Support';
const icons: LucideIcon[] = [
  BookOpen,
  ChartNoAxesCombined,
  MessageCircle,
  Monitor,
  Users,
  CreditCard,
];
export default function Help({ help }: { help: HelpData }) {
  const c = useSupportCopy();
  const [query, setQuery] = useState('');
  const term = query.trim().toLocaleLowerCase('ru');
  const sections = term
    ? help.sections.filter(
        (section) =>
          section.url &&
          (section.title + ' ' + section.description).toLocaleLowerCase('ru').includes(term),
      )
    : help.sections;
  const articles = help.articles.filter(
    (article) => !term || article.title.toLocaleLowerCase('ru').includes(term),
  );
  return (
    <>
      <Head title={c.help} />
      <SupportPage
        active="help"
        title={c.help}
        subtitle={c.helpSubtitle}
        actions={
          <Link
            href="/help/support"
            className={[
              'inline-flex min-h-11 items-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-(--color-on-action)',
              focus,
            ].join(' ')}
          >
            {c.tickets}
          </Link>
        }
      >
        {!help.configured && (
          <div className="mb-5 flex items-start gap-3 rounded-xl bg-(--color-warning-soft) p-4 text-sm leading-6 text-(--color-warning-ink)">
            <Info className="mt-0.5 shrink-0" size={22} />
            <div>
              <p className="font-semibold">{c.unconfigured}</p>
              <p>{c.unconfiguredHint}</p>
            </div>
          </div>
        )}
        <section className={[panel, 'mb-5 p-5'].join(' ')}>
          <div className="flex items-end gap-3">
            <div className="min-w-0 flex-1">
              <Input
                label={c.search}
                placeholder={c.searchHint}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                disabled={!help.configured}
              />
            </div>
            {query && (
              <Button variant="ghost" onClick={() => setQuery('')}>
                {c.clear}
              </Button>
            )}
            <Search aria-hidden="true" size={22} className="mb-2 shrink-0 text-(--color-link)" />
          </div>
        </section>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {sections.map((section, index) => {
            const Icon =
              icons[help.sections.findIndex((item) => item.id === section.id)] ??
              icons[index % icons.length];
            const content = (
              <>
                <span className="grid size-12 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)">
                  <Icon size={26} />
                </span>
                <div className="min-w-0 flex-1">
                  <h2 className="break-words text-lg font-bold">{section.title}</h2>
                  {section.description && (
                    <p className="mt-1 text-sm leading-5 text-(--color-ink-muted)">
                      {section.description}
                    </p>
                  )}
                  {!section.url && (
                    <p className="mt-2 text-sm text-(--color-ink-muted)">{c.soon}</p>
                  )}
                </div>
                {section.url && (
                  <ExternalLink
                    aria-label={c.external}
                    size={18}
                    className="shrink-0 text-(--color-link)"
                  />
                )}
              </>
            );
            return section.url ? (
              <a
                key={section.id}
                href={section.url}
                target="_blank"
                rel="noopener noreferrer"
                className={[
                  panel,
                  'flex min-h-32 items-center gap-4 p-5 hover:bg-(--color-surface-muted)',
                  focus,
                ].join(' ')}
              >
                {content}
              </a>
            ) : (
              <section
                key={section.id}
                aria-disabled="true"
                className={[panel, 'flex min-h-32 items-center gap-4 p-5'].join(' ')}
              >
                {content}
              </section>
            );
          })}
        </div>
        {term && !sections.length && !articles.length && (
          <div className="mt-5">
            <EmptyState title={c.nothingFound} description={c.nothingFoundHint} />
          </div>
        )}
        <section className={[panel, 'mt-5 p-5 sm:p-6'].join(' ')}>
          <h2 className="text-xl font-bold">{c.popular}</h2>
          {articles.length ? (
            <ul className="mt-4 divide-y divide-(--color-border)">
              {articles.map((article) => (
                <li key={article.id}>
                  <a
                    href={article.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={[
                      'flex min-h-14 items-center gap-3 rounded-lg py-3 text-sm font-medium text-(--color-link)',
                      focus,
                    ].join(' ')}
                  >
                    <FileText size={22} className="shrink-0" />
                    <span className="min-w-0 flex-1 break-words">{article.title}</span>
                    <ExternalLink size={18} aria-label={c.external} />
                  </a>
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-5 text-sm text-(--color-ink-muted)">
              {term ? c.nothingFound : c.noArticles}
            </p>
          )}
          <p className="mt-5 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">
            {c.externalHint}
          </p>
          {help.knowledge_base_url && (
            <a
              href={help.knowledge_base_url}
              target="_blank"
              rel="noopener noreferrer"
              className={[
                'mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-(--color-link)',
                focus,
              ].join(' ')}
            >
              {c.external}
              <ExternalLink size={17} />
            </a>
          )}
        </section>
      </SupportPage>
    </>
  );
}
