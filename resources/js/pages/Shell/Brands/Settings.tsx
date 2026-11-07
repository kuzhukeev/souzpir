import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Switch, Tabs } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import { General } from '@/features/Shell/Brands/General';
import { ThemeForm } from '@/features/Shell/Brands/ThemeForm';
import { Domains } from '@/features/Shell/Brands/Domains';
import { Modules } from '@/features/Shell/Brands/Modules';
import { ArchiveBrand } from '@/features/Shell/Brands/ArchiveBrand';
import { focus, FormErrors, panel, Row, Saved } from '@/features/Shell/Brands/shared';
import type { BrandSettings, BrandSettingsProps } from '@/types/Shell/Brands';

const tabIds = ['general', 'theme', 'domains', 'modules', 'ai_policy', 'archive'] as const;
type TabId = (typeof tabIds)[number];
function tabFromUrl(): TabId {
  if (typeof window === 'undefined') return 'general';
  const value = new URLSearchParams(window.location.search).get('tab');
  return tabIds.find((id) => id === value) ?? 'general';
}

type AiDataPolicyValue = {
  conversation_content: boolean;
  knowledge_internal: boolean;
  vision_question: boolean;
  knowledge_public: boolean;
};

const AI_POLICY_FIELDS: { key: keyof AiDataPolicyValue; label: string; hint: string }[] = [
  {
    key: 'conversation_content',
    label: 'Переписка с клиентами',
    hint: 'Содержимое диалогов продавца с клиентами передаётся во внешний ИИ.',
  },
  {
    key: 'knowledge_internal',
    label: 'Внутренние знания',
    hint: 'Внутренняя база знаний бренда передаётся во внешний ИИ.',
  },
  {
    key: 'vision_question',
    label: 'Вопросы замера Видения',
    hint: 'Вопросы и ответы замера видимости передаются во внешний ИИ.',
  },
  {
    key: 'knowledge_public',
    label: 'Публичные знания',
    hint: 'Публичная часть базы знаний передаётся во внешний ИИ для замеров Видения.',
  },
];

function AiDataPolicy({
  brand,
  policy,
  editable,
}: {
  brand: BrandSettings;
  policy: AiDataPolicyValue;
  editable: boolean;
}) {
  const [values, setValues] = useState(policy);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const change = (key: keyof AiDataPolicyValue, checked: boolean) => {
    const next = { ...values, [key]: checked };
    setValues(next);
    setError('');
    setSaved(false);
    router.patch('/brands/' + brand.id + '/settings/ai-policy', next, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onSuccess: () => setSaved(true),
      onError: (errors) => {
        setValues(policy);
        setError(Object.values(errors).join(' '));
      },
      onNetworkError: () => {
        setValues(policy);
        setError('Не удалось связаться с сервером.');
        return false;
      },
      onHttpException: () => {
        setValues(policy);
        setError('Не удалось сохранить политику.');
        return false;
      },
    });
  };
  return (
    <div className={panel + ' flex flex-col gap-5'}>
      <div>
        <h2 className="text-xl font-bold">Передача данных в ИИ</h2>
        <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
          Без явного согласия владельца внешние вызовы ИИ для выбранного класса данных
          отклоняются.
        </p>
      </div>
      <FormErrors errors={{}} transport={error} />
      <div className="flex flex-col gap-4">
        {AI_POLICY_FIELDS.map((field) => (
          <Row key={field.key} label={field.label} hint={field.hint}>
            <Switch
              label={field.label + ': ' + (values[field.key] ? 'разрешено' : 'запрещено')}
              checked={values[field.key]}
              disabled={!editable || pending}
              onChange={(checked) => change(field.key, checked)}
            />
          </Row>
        ))}
      </div>
      {!editable && (
        <p className="text-sm text-(--color-ink-muted)">
          Менять передачу данных в ИИ может только владелец организации.
        </p>
      )}
      <Saved visible={saved} />
    </div>
  );
}

export default function Settings({
  targetBrand,
  domains,
  moduleItems,
  aiDataPolicy,
  permissions,
  networkChecksEnabled,
}: BrandSettingsProps & {
  aiDataPolicy: AiDataPolicyValue;
  permissions: { manageAiPolicy: boolean };
}) {
  const [tab, setTab] = useState<TabId>(tabFromUrl);
  useEffect(() => {
    const syncTab = () => setTab(tabFromUrl());
    window.addEventListener('popstate', syncTab);
    return () => window.removeEventListener('popstate', syncTab);
  }, []);
  function changeTab(value: string) {
    const next = tabIds.find((id) => id === value) ?? 'general';
    const url = new URL(window.location.href);
    if (next === 'general') url.searchParams.delete('tab');
    else url.searchParams.set('tab', next);
    window.history.pushState(window.history.state, '', url);
    setTab(next);
  }
  return (
    <>
      <Head title={targetBrand.name + ' — настройки бренда'} />
      <CabinetLayout
        brandSwitchReady
        breadcrumbs={[{ label: 'Бренды', href: '/brands' }, { label: targetBrand.name }]}
      >
        <div className="mx-auto max-w-[1440px] [&_button:not(:disabled)]:cursor-pointer [&_[role=tab]]:cursor-pointer">
          <Link
            href="/brands"
            className={
              'mb-4 inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ' +
              focus
            }
          >
            <ArrowLeft size={17} aria-hidden="true" /> Все бренды
          </Link>
          <header className="mb-6">
            <h1 className="break-words text-3xl font-bold leading-tight">{targetBrand.name}</h1>
            <p className="mt-2 text-base text-(--color-ink-muted)">
              Настройки бренда и подключённых модулей
            </p>
          </header>
          <Tabs
            label="Настройки бренда"
            value={tab}
            onChange={changeTab}
            tabs={[
              {
                id: 'general',
                label: 'Основное',
                content: (
                  <General
                    key={targetBrand.id}
                    brand={targetBrand}
                    editable={permissions.manage}
                    networkChecksEnabled={networkChecksEnabled}
                  />
                ),
              },
              {
                id: 'theme',
                label: 'Оформление кабинета',
                content: (
                  <ThemeForm
                    key={targetBrand.id}
                    brand={targetBrand}
                    editable={permissions.manage}
                  />
                ),
              },
              {
                id: 'domains',
                label: 'Домены',
                content: (
                  <Domains
                    key={targetBrand.id}
                    brand={targetBrand}
                    domains={domains}
                    networkChecksEnabled={networkChecksEnabled}
                    editable={permissions.manage}
                  />
                ),
              },
              {
                id: 'modules',
                label: 'Модули',
                content: (
                  <Modules
                    brand={targetBrand}
                    modules={moduleItems}
                    editable={permissions.manage}
                  />
                ),
              },
              {
                id: 'ai_policy',
                label: 'Передача данных в ИИ',
                content: (
                  <AiDataPolicy
                    key={targetBrand.id}
                    brand={targetBrand}
                    policy={aiDataPolicy}
                    editable={permissions.manageAiPolicy}
                  />
                ),
              },
              {
                id: 'archive',
                label: 'Архив',
                content: <ArchiveBrand brand={targetBrand} editable={permissions.manage} />,
              },
            ]}
          />
        </div>
      </CabinetLayout>
    </>
  );
}
