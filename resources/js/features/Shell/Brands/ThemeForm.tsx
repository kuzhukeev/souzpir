import { brandThemes } from './presets';
import { base } from './urls';
import { useEffect, useId, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { Button, Checkbox, Input, Switch } from '@/components/ui';
import { ThemePreview, ThemeSwatch } from './ThemePreview';
import { FormErrors, hiddenLabel, panel, Row, Saved } from './shared';
import type { BrandSettings } from '@/types/Shell/Brands';
export function ThemeForm({ brand, editable }: { brand: BrandSettings; editable: boolean }) {
  const form = useForm({
    _method: 'PATCH',
    theme: brand.theme,
    subtitle: brand.settings.theme?.subtitle ?? '',
    promo_card: brand.settings.theme?.promo_card ?? false,
    logo: null as File | null,
    remove_logo: false,
  });
  const [transport, setTransport] = useState('');
  const [uploadVersion, setUploadVersion] = useState(0);
  const [localLogo, setLocalLogo] = useState<string | null>(null);
  const radioName = useId();
  const canEdit = editable && brand.status === 'active';
  useEffect(
    () => () => {
      if (localLogo) URL.revokeObjectURL(localLogo);
    },
    [localLogo],
  );
  const logo = form.data.remove_logo ? null : form.data.logo ? localLogo : brand.logo_url;
  const fail = () => {
    setTransport('Не удалось сохранить оформление. Попробуйте ещё раз.');
    return false;
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setTransport('');
    form.post(base(brand) + '/theme', {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        form.setData('logo', null);
        form.setData('remove_logo', false);
        setLocalLogo(null);
        setUploadVersion((value) => value + 1);
      },
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  return (
    <form onSubmit={submit} className="space-y-5">
      <div className={panel + ' space-y-6'}>
        <FormErrors errors={form.errors} transport={transport} />
        <Row label="Пресет оформления" hint="Выберите цветовую тему кабинета">
          <fieldset>
            <legend className="sr-only">Цветовая тема</legend>
            <div className="grid gap-3 sm:grid-cols-3">
              {brandThemes.map((item) => (
                <label
                  key={item.value}
                  className={
                    'min-w-0 cursor-pointer rounded-xl border-2 p-3 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--color-focus) ' +
                    (form.data.theme === item.value
                      ? 'border-(--color-action) bg-(--color-info-soft)'
                      : 'border-(--color-border)')
                  }
                >
                  <span className="flex items-center gap-2 text-sm font-semibold">
                    <input
                      type="radio"
                      name={radioName}
                      value={item.value}
                      checked={form.data.theme === item.value}
                      disabled={!canEdit}
                      onChange={() => form.setData('theme', item.value)}
                      className="size-4 shrink-0 accent-(--color-action)"
                    />
                    {item.label}
                  </span>
                  <p className="mt-2 text-sm leading-5 text-(--color-ink-muted)">
                    {item.description}
                  </p>
                  <ThemeSwatch theme={item.value} />
                </label>
              ))}
            </div>
          </fieldset>
        </Row>
        <Row label="Предпросмотр навигации" hint="Сайдбар и шапка с выбранным оформлением">
          <ThemePreview
            theme={form.data.theme}
            name={brand.name}
            subtitle={form.data.subtitle}
            promo={form.data.promo_card}
            logo={logo}
          />
        </Row>
      </div>
      <div className={panel + ' space-y-6'}>
        <Row label="Логотип бренда" hint="PNG или SVG, до 1 МБ">
          <div className="flex flex-wrap items-center gap-4">
            {logo && (
              <img
                src={logo}
                alt="Логотип бренда"
                className="size-20 rounded-xl border border-(--color-border) object-contain p-2"
              />
            )}
            <Input
              className="min-w-0 flex-1 basis-52"
              key={uploadVersion}
              label="Загрузить логотип"
              type="file"
              accept="image/png,image/svg+xml"
              error={form.errors.logo}
              disabled={!canEdit}
              onChange={(e) => {
                const file = e.target.files?.[0] ?? null;
                form.setData('logo', file);
                form.setData('remove_logo', false);
                setLocalLogo(file ? URL.createObjectURL(file) : null);
              }}
            />
          </div>
          {brand.logo_url && (
            <div className="mt-3">
              <Checkbox
                label="Удалить текущий логотип"
                checked={form.data.remove_logo}
                disabled={!canEdit || form.data.logo !== null}
                onChange={(e) => form.setData('remove_logo', e.target.checked)}
              />
            </div>
          )}
        </Row>
        <Row label="Подпись под логотипом" hint="Не более 60 символов">
          <Input
            className={hiddenLabel}
            label="Подпись под логотипом"
            value={form.data.subtitle}
            onChange={(e) => form.setData('subtitle', e.target.value)}
            error={form.errors.subtitle}
            maxLength={60}
            disabled={!canEdit}
          />
        </Row>
        <Row label="Промо-карта">
          <Switch
            label="Показывать промо-карту в меню"
            checked={form.data.promo_card}
            onChange={(value) => form.setData('promo_card', value)}
            disabled={!canEdit}
          />
        </Row>
        <div className="flex flex-wrap items-center justify-end gap-4 border-t border-(--color-border) pt-5">
          <Saved visible={form.recentlySuccessful} />
          <Button type="submit" loading={form.processing} disabled={!canEdit}>
            <Save size={18} aria-hidden="true" /> Сохранить оформление
          </Button>
        </div>
      </div>
    </form>
  );
}
