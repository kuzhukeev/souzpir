import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Tabs } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import { General } from '@/features/Shell/Brands/General';
import { ThemeForm } from '@/features/Shell/Brands/ThemeForm';
import { Domains } from '@/features/Shell/Brands/Domains';
import { Modules } from '@/features/Shell/Brands/Modules';
import { ArchiveBrand } from '@/features/Shell/Brands/ArchiveBrand';
import { focus } from '@/features/Shell/Brands/shared';
import type { BrandSettingsProps } from '@/types/Shell/Brands';
export default function Settings({
  targetBrand,
  domains,
  moduleItems,
  permissions,
}: BrandSettingsProps) {
  const [tab, setTab] = useState('general');
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
            onChange={setTab}
            tabs={[
              {
                id: 'general',
                label: 'Основное',
                content: (
                  <General key={targetBrand.id} brand={targetBrand} editable={permissions.manage} />
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
