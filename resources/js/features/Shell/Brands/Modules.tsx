import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check, Eye, MessageCircle } from 'lucide-react';
import { Button, Modal, Switch } from '@/components/ui';
import { focus, FormErrors, panel } from './shared';
import type { BrandModule, BrandModuleKey, BrandSettings } from '@/types/Shell/Brands';
export function Modules({
  brand,
  modules,
  editable,
}: {
  brand: BrandSettings;
  modules: Record<BrandModuleKey, BrandModule & { enabled_at?: string | null }>;
  editable: boolean;
}) {
  const [confirm, setConfirm] = useState<BrandModuleKey | null>(null);
  const [pending, setPending] = useState<BrandModuleKey | null>(null);
  const [error, setError] = useState('');
  const change = (module: BrandModuleKey, enabled: boolean) => {
    setError('');
    router.put(
      '/brands/' + brand.id + '/modules/' + module,
      { enabled },
      {
        preserveScroll: true,
        onStart: () => setPending(module),
        onFinish: () => setPending(null),
        onError: (errors) => setError(Object.values(errors).join(' ')),
        onSuccess: () => setConfirm(null),
        onNetworkError: () => {
          setError('Не удалось связаться с сервером.');
          return false;
        },
        onHttpException: () => {
          setError('Не удалось изменить модуль.');
          return false;
        },
      },
    );
  };
  return (
    <>
      <FormErrors errors={{}} transport={error} />
      <div className="grid gap-5 lg:grid-cols-2">
        {(['vision', 'seller'] as const).map((key) => {
          const module = modules[key];
          const title = key === 'vision' ? 'Видение' : 'ИИ-продавец';
          const Icon = key === 'vision' ? Eye : MessageCircle;
          return (
            <article key={key} className={panel + ' flex flex-col'}>
              <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-(--color-info-soft) text-(--color-link)">
                  <Icon size={24} aria-hidden="true" />
                </span>
                <div className="min-w-0">
                  <h2 className="text-xl font-bold">{title}</h2>
                  <p className="mt-1 flex items-center gap-2 text-sm text-(--color-ink-muted)">
                    {module.enabled && <Check size={16} aria-hidden="true" />}
                    {module.enabled
                      ? (module.enabled_at ? `Подключён с ${new Date(module.enabled_at).toLocaleDateString('ru-RU')}` : 'Подключён')
                      : module.configured_enabled && !module.allowed
                        ? 'Недоступен по тарифу'
                        : 'Не подключён'}
                  </p>
                </div>
              </div>
              <p className="mt-5 text-sm leading-6 text-(--color-ink-muted)">
                {key === 'vision'
                  ? 'Анализ видимости бренда, замеры и отчёты.'
                  : 'Диалоги с клиентами, база знаний и настройки продавца.'}
              </p>
              <div className="mt-4 text-sm"><p className="font-semibold">Что входит</p><ul className="mt-2 list-disc pl-5 text-(--color-ink-muted)">{(key === 'vision' ? ['Анкета и вопросы', 'Замеры видимости', 'Отчёты и рекомендации'] : ['Ответы клиентам', 'База знаний', 'Виджет и каналы']).map(feature => <li key={feature}>{feature}</li>)}</ul></div>
              <div className="my-5 border-y border-(--color-border) py-4 text-sm">
                <p className="font-semibold">Использование и лимит</p>
                <p className="mt-2 text-sm text-(--color-ink-muted)">
                  {key === 'seller' ? 'Диалоги за месяц' : 'Замеры за неделю'}:{' '}
                  {module.usage.status === 'ready' ? module.usage.value : '—'} /{' '}
                  {module.quota.configured ? module.quota.value : '—'}
                </p>
                {!module.quota.configured && (
                  <p className="mt-1 text-sm text-(--color-ink-muted)">Лимит не задан</p>
                )}
                <p className="mt-4 font-semibold">Доступность по тарифу</p>
                <p className="mt-2 text-sm text-(--color-ink-muted)">
                  {!module.limit.configured
                    ? 'Ограничение доступности не задано'
                    : module.allowed
                      ? 'Модуль доступен'
                      : 'Модуль недоступен по тарифу'}
                </p>
              </div>
              <div className="mt-auto flex flex-wrap items-center justify-between gap-3">
                <Switch
                  label={
                    title +
                    ': ' +
                    (pending === key
                      ? 'сохраняем…'
                      : module.configured_enabled
                        ? 'включён'
                        : 'выключен')
                  }
                  checked={module.configured_enabled}
                  disabled={
                    !editable ||
                    brand.status !== 'active' ||
                    pending !== null ||
                    (!module.allowed && !module.configured_enabled)
                  }
                  onChange={(enabled) => (enabled ? change(key, true) : setConfirm(key))}
                />
                <Link
                  href={'/brands/' + brand.id + '/modules'}
                  className={
                    'inline-flex min-h-10 cursor-pointer items-center text-sm font-semibold text-(--color-link) ' +
                    focus
                  }
                >
                  Настроить модуль
                </Link>
              </div>
            </article>
          );
        })}
      </div>
      <Modal open={confirm !== null} title="Отключить модуль" onClose={() => setConfirm(null)}>
        <p className="text-sm leading-6 text-(--color-ink-muted)">
          Модуль станет недоступен в кабинете. Сохранённые данные останутся не менее 90 дней.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setConfirm(null)}>
            Отмена
          </Button>
          <Button
            variant="danger"
            loading={pending !== null}
            onClick={() => {
              if (confirm) change(confirm, false);
            }}
          >
            Отключить
          </Button>
        </div>
      </Modal>
    </>
  );
}
