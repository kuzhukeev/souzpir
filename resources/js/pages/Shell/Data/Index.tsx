import CopyHash from './CopyHash';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from '@/lib/i18n';
import { Bot, Check, Download, FileText, Mail } from 'lucide-react';
import { Button, Input, Select, Switch } from '@/components/ui';
import { Errors, Page, panel } from '@/features/Shell/Organizations/Shared';
import type { DataConsent, DataExport, DataModule } from '@/types/Shell/Data';

type Props = { consents: DataConsent; exports: DataExport[]; modules: DataModule[]; permissions: { can_export: boolean } };

function date(value: string | null, missing: string): string {
  if (!value) return missing;
  const parsed = new Date(value);
  return Number.isNaN(parsed.valueOf()) ? value : new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long' }).format(parsed);
}

export default function Index({ consents, exports: archives, modules, permissions }: Props) {
  const t = useTranslation() as (key: string, replacements?: Record<string, string>) => string;
  const tr = (key: string, replacements?: Record<string, string>) => t(`Shell/Data/messages.${key}`, replacements);
  const form = useForm({ module: modules[0]?.value ?? '', from: '', to: '' });
  const consent = useForm({ marketing_opt_in: consents.marketing_opt_in });
  const [transport, setTransport] = useState('');
  const [notice, setNotice] = useState('');
  const [rangeError, setRangeError] = useState('');
  const failure = () => { setTransport(tr('network_error')); return false; };
  const setRangeDate = (field: 'from' | 'to', value: string) => {
    form.setData(field, value);
    const from = field === 'from' ? value : form.data.from;
    const to = field === 'to' ? value : form.data.to;
    if (!from || !to || from <= to) setRangeError('');
  };
  const submit = (event: FormEvent) => {
    event.preventDefault(); setTransport(''); setNotice('');
    if (form.data.from && form.data.to && form.data.from > form.data.to) {
      setRangeError('Дата «С» не может быть позже даты «По».');
      return;
    }
    setRangeError('');
    form.post('/settings/data/exports', { preserveScroll: true, onSuccess: () => setNotice(tr('archive_ready')), onNetworkError: failure, onHttpException: failure });
  };
  const updateConsent = (value: boolean) => {
    setTransport(''); setNotice('');
    consent.transform(() => ({ marketing_opt_in: value }));
    consent.patch('/settings/data/consents', { preserveScroll: true, onSuccess: () => { consent.setData('marketing_opt_in', value); setNotice(tr('consent_saved')); }, onNetworkError: failure, onHttpException: failure });
  };
  return <><Head title={tr('page_title')} /><Page title={tr('page_title')} subtitle={tr('page_subtitle')}>
    <div className="space-y-5">
      <div><Errors errors={{ ...form.errors, ...consent.errors, ...(rangeError ? { from: rangeError } : {}) }} transport={transport} /><p role="status" className="text-sm text-(--color-success)">{notice}</p></div>
      <section className={panel} aria-labelledby="storage-title">
        <h2 id="storage-title" className="text-xl font-bold">Хранение данных</h2>
        <p className="mt-2 text-sm text-(--color-ink-muted)">Срок хранения данных и автоматическая очистка пока не подтверждены. Для выгрузки можно выбрать период до 365 дней.</p>
      </section>
      <section className={panel} aria-labelledby="account-title">
        <h2 id="account-title" className="text-xl font-bold">Удаление аккаунта</h2>
        <p className="mt-2 text-sm text-(--color-ink-muted)">Самостоятельное удаление аккаунта пока недоступно. Обратитесь в поддержку.</p>
        <a href="/help/support" className="mt-3 inline-block cursor-pointer text-sm font-semibold text-(--color-link) underline">Открыть поддержку</a>
      </section>
      <section className={panel} aria-labelledby="consents-title">
        <h2 id="consents-title" className="text-xl font-bold">{tr('consents')}</h2>
        <ul className="mt-2 divide-y divide-(--color-border)">
          <li className="flex flex-wrap items-center gap-4 py-4"><span className="grid size-12 shrink-0 place-items-center rounded-full bg-(--color-warning-soft) text-(--color-warning-ink)"><FileText aria-hidden="true" size={22} /></span><div className="min-w-0 flex-1"><h3 className="font-semibold">{tr('terms')}</h3><p className="text-sm text-(--color-ink-muted)">{tr('terms_description')}</p></div><p className="flex w-full items-center gap-2 text-sm sm:ml-auto sm:w-auto">{consents.terms_accepted_at && <Check aria-hidden="true" size={18} className="text-(--color-success)" />}{consents.terms_accepted_at ? tr('accepted', { date: date(consents.terms_accepted_at, tr('unknown_date')) }) : tr('not_accepted')}</p></li>
          <li className="flex flex-wrap items-center gap-4 py-4"><span className="grid size-12 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)"><Bot aria-hidden="true" size={22} /></span><div className="min-w-0 flex-1"><h3 className="font-semibold">{tr('ai')}</h3><p className="text-sm text-(--color-ink-muted)">{tr('ai_description')}</p></div><p className="flex w-full items-center gap-2 text-sm sm:ml-auto sm:w-auto">{consents.ai_consent_at && <Check aria-hidden="true" size={18} className="text-(--color-success)" />}{consents.ai_consent_at ? tr('ai_accepted', { date: date(consents.ai_consent_at, tr('unknown_date')) }) : tr('ai_not_accepted')}</p></li>
          <li className="flex flex-wrap items-center gap-4 py-4"><span className="grid size-12 shrink-0 place-items-center rounded-full bg-(--color-warning-soft) text-(--color-warning-ink)"><Mail aria-hidden="true" size={22} /></span><div className="min-w-0 flex-1"><h3 className="font-semibold">{tr('news')}</h3><p className="text-sm text-(--color-ink-muted)">{tr('news_description')}</p>{consents.marketing_changed_at && <p className="text-sm text-(--color-ink-muted)">{tr('news_changed', { date: date(consents.marketing_changed_at, tr('unknown_date')) })}</p>}</div><span className="w-full sm:ml-auto sm:w-auto [&_button]:cursor-pointer"><Switch label={consent.data.marketing_opt_in ? tr('news') : tr('news_off')} checked={consent.data.marketing_opt_in} onChange={updateConsent} disabled={consent.processing} /></span></li>
        </ul>
      </section>
      {permissions.can_export && <section className={panel} aria-labelledby="exports-title">
        <h2 id="exports-title" className="text-xl font-bold">{tr('export_title')}</h2>
        <p className="mt-1 text-sm text-(--color-ink-muted)">{tr('export_description')}</p>
        {modules.length === 0 && <p role="status" className="mt-3 text-sm text-(--color-ink-muted)">{tr('module_unavailable')}</p>}
        {modules.length === 1 && modules[0].value === 'cabinet' && <p className="mt-2 text-sm text-(--color-ink-muted)">{tr('export_limit')}</p>}

        <form onSubmit={submit} className="grid items-end gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
          <Select className="[&_button]:cursor-pointer" label={tr('module')} value={form.data.module} options={modules} onValueChange={(value) => form.setData('module', value)} error={form.errors.module} placeholder={tr('module_placeholder')} />
          <Input type="date" label={tr('from')} value={form.data.from} onChange={(event) => setRangeDate('from', event.target.value)} error={rangeError || form.errors.from} />
          <Input type="date" label={tr('to')} value={form.data.to} onChange={(event) => setRangeDate('to', event.target.value)} error={form.errors.to} />
          <Button type="submit" className="cursor-pointer" loading={form.processing} disabled={!modules.length}><Download aria-hidden="true" size={18} />{tr('prepare')}</Button>
        </form>
        <div className="mt-6" aria-busy={form.processing}>
          {archives.length === 0 ? <p className="rounded-xl bg-(--color-surface-muted) p-4 text-sm text-(--color-ink-muted)">{tr(modules.length ? 'empty' : 'empty_unavailable')}</p> : <ul className="divide-y divide-(--color-border)">{archives.map((archive) => <li key={archive.id} className="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto] lg:items-center"><div><p className="font-semibold">{modules.find((module) => module.value === archive.module)?.label ?? archive.module}</p><p className="text-sm text-(--color-ink-muted)">{tr('archive_dates', { created: date(archive.created_at, tr('unknown_date')), expires: date(archive.expires_at, tr('unknown_date')) })}</p></div><span className="text-sm text-(--color-ink-muted)">SHA-256</span><div><code className="block break-all text-sm">{archive.sha256}</code><CopyHash hash={archive.sha256} /></div>{archive.download_url ? <a href={archive.download_url} className="inline-flex min-h-10 cursor-pointer items-center gap-2 font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"><Download aria-hidden="true" size={18} />{tr('download')}</a> : <span className="text-sm text-(--color-ink-muted)">{tr('unavailable')}</span>}</li>)}</ul>}
        </div>
      </section>}
    </div>
  </Page></>;
}
