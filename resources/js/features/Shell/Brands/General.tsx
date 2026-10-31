import { base } from './urls';
import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Info, Plus, Save, X } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { focus, FormErrors, hiddenLabel, panel, Row, Saved } from './shared';
import type { BrandSettings } from '@/types/Shell/Brands';
export function General({
  brand,
  editable,
  networkChecksEnabled,
}: {
  brand: BrandSettings;
  editable: boolean;
  networkChecksEnabled: boolean;
}) {
  const form = useForm({
    name: brand.name,
    website: brand.website ?? '',
    aliases: brand.settings.aliases ?? [],
    industry: brand.industry ?? '',
    market: brand.market ?? '',
    language: brand.language ?? 'ru',
    timezone: brand.timezone ?? 'Europe/Moscow',
  });
  const [alias, setAlias] = useState('');
  const [transport, setTransport] = useState('');
  const [checking, setChecking] = useState(false);
  const canEdit = editable && brand.status === 'active';
  const aliasesWithDraft = () =>
    alias.trim() && !form.data.aliases.includes(alias.trim())
      ? [...form.data.aliases, alias.trim()]
      : form.data.aliases;
  const addAlias = () => {
    form.setData('aliases', aliasesWithDraft());
    setAlias('');
  };
  const fail = () => {
    setTransport('Не удалось связаться с сервером. Попробуйте ещё раз.');
    return false;
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setTransport('');
    form.transform((data) => ({ ...data, aliases: aliasesWithDraft() }));
    form.patch(base(brand) + '/general', {
      preserveScroll: true,
      onSuccess: (page) => {
        const saved = page.props.targetBrand as BrandSettings;
        form.setData('aliases', saved.settings.aliases ?? []);
        setAlias('');
      },
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  const checkWebsite = () => {
    setTransport('');
    router.post(
      base(brand) + '/website/check',
      {},
      {
        preserveScroll: true,
        onStart: () => setChecking(true),
        onFinish: () => setChecking(false),
        onError: (errors) => setTransport(Object.values(errors).join(' ')),
        onNetworkError: fail,
        onHttpException: fail,
      },
    );
  };
  return (
    <form onSubmit={submit} className={panel + ' space-y-6'}>
      <FormErrors errors={form.errors} transport={transport} />
      <Row label="Название бренда" hint="Отображается в кабинете и списке брендов">
        <Input
          className={hiddenLabel}
          label="Название бренда"
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          error={form.errors.name}
          maxLength={160}
          required
          disabled={!canEdit}
        />
      </Row>
      <Row label="Сайт" hint="Проверка использует сохранённый адрес">
        <div className="flex flex-wrap items-end gap-3">
          <Input
            className={'min-w-0 flex-1 basis-64 ' + hiddenLabel}
            label="Адрес сайта"
            type="url"
            value={form.data.website}
            onChange={(e) => form.setData('website', e.target.value)}
            error={form.errors.website}
            placeholder="https://example.ru"
            maxLength={2048}
            disabled={!canEdit}
          />
          <Button
            variant="secondary"
            loading={checking}
            disabled={
              !networkChecksEnabled ||
              !canEdit ||
              !brand.website ||
              form.data.website !== (brand.website ?? '')
            }
            onClick={checkWebsite}
          >
            Проверить
          </Button>
        </div>
        {!networkChecksEnabled && (
          <p className="mt-3 text-sm text-(--color-ink-muted)">Внешние проверки пока недоступны.</p>
        )}
        <div className="mt-3 text-sm" aria-live="polite">
          {brand.website_check ? (
            <p
              className={
                brand.website_check.status === 'available'
                  ? 'text-(--color-success)'
                  : 'text-(--color-danger-ink)'
              }
            >
              {brand.website_check.status === 'available' ? 'Сайт доступен' : 'Сайт недоступен'} ·{' '}
              {new Date(brand.website_check.checked_at).toLocaleString('ru-RU')}
            </p>
          ) : (
            <p className="text-(--color-ink-muted)">Сайт ещё не проверен</p>
          )}
          {brand.website_check?.error && (
            <p className="mt-1 text-(--color-danger-ink)">{brand.website_check.error}</p>
          )}
          {brand.website_check?.robots_blocked && (
            <p className="mt-3 flex items-start gap-2 rounded-lg bg-(--color-warning-soft) p-3 text-(--color-warning-ink)">
              <Info size={18} className="mt-0.5 shrink-0" aria-hidden="true" /> robots.txt
              ограничивает доступ ИИ-ботов. Проверьте правила доступа на сайте.
            </p>
          )}
        </div>
      </Row>
      <Row label="Алиасы бренда" hint="Другие названия и варианты написания">
        <div className="mb-3 flex flex-wrap gap-2">
          {form.data.aliases.map((value, index) => (
            <span
              key={value + '-' + index}
              className="inline-flex max-w-full items-center gap-2 rounded-lg bg-(--color-info-soft) px-3 py-2 text-sm text-(--color-info-ink)"
            >
              <span className="break-words">{value}</span>
              <button
                type="button"
                aria-label={'Удалить алиас ' + value}
                disabled={!canEdit}
                className={'shrink-0 rounded ' + focus}
                onClick={() =>
                  form.setData(
                    'aliases',
                    form.data.aliases.filter((_, i) => i !== index),
                  )
                }
              >
                <X size={17} aria-hidden="true" />
              </button>
            </span>
          ))}
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <Input
            className="min-w-0 flex-1 basis-52"
            label="Добавить алиас"
            value={alias}
            onChange={(e) => setAlias(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                addAlias();
              }
            }}
            hint="Введите название и нажмите Enter"
            disabled={!canEdit}
          />
          <Button variant="secondary" onClick={addAlias} disabled={!canEdit || !alias.trim()}>
            <Plus size={17} aria-hidden="true" /> Добавить
          </Button>
        </div>
      </Row>
      <Row label="Сфера деятельности">
        <Input
          className={hiddenLabel}
          label="Сфера деятельности"
          value={form.data.industry}
          onChange={(e) => form.setData('industry', e.target.value)}
          error={form.errors.industry}
          disabled={!canEdit}
        />
      </Row>
      <Row label="Регион">
        <Input
          className={hiddenLabel}
          label="Регион"
          value={form.data.market}
          onChange={(e) => form.setData('market', e.target.value)}
          error={form.errors.market}
          disabled={!canEdit}
        />
      </Row>
      <Row label="Язык">
        <Input
          className={hiddenLabel}
          label="Код языка"
          hint="Например, ru"
          value={form.data.language}
          onChange={(e) => form.setData('language', e.target.value)}
          error={form.errors.language}
          maxLength={10}
          required
          disabled={!canEdit}
        />
      </Row>
      <Row label="Часовой пояс">
        <Input
          className={hiddenLabel}
          label="Часовой пояс"
          hint="Например, Europe/Moscow"
          value={form.data.timezone}
          onChange={(e) => form.setData('timezone', e.target.value)}
          error={form.errors.timezone}
          required
          disabled={!canEdit}
        />
      </Row>
      <div className="flex flex-wrap items-center justify-end gap-4 border-t border-(--color-border) pt-5">
        <Saved visible={form.recentlySuccessful} />
        <Button type="submit" loading={form.processing} disabled={!canEdit}>
          <Save size={18} aria-hidden="true" /> Сохранить изменения
        </Button>
      </div>
    </form>
  );
}
