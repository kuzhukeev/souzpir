import { base } from './urls';
import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Copy, Globe, Info, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { Button, Input, Modal, Select } from '@/components/ui';
import { focus, FormErrors, panel } from './shared';
import type { BrandDomain, BrandSettings } from '@/types/Shell/Brands';
export function Domains({
  brand,
  domains,
  editable,
  networkChecksEnabled,
}: {
  brand: BrandSettings;
  domains: BrandDomain[];
  editable: boolean;
  networkChecksEnabled: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [instructions, setInstructions] = useState<BrandDomain | null>(null);
  const [remove, setRemove] = useState<BrandDomain | null>(null);
  const [pending, setPending] = useState<string | null>(null);
  const [transport, setTransport] = useState('');
  const form = useForm({ host: '', purpose: 'widget_and_visibility', verification_method: 'file' });
  const canEdit = editable && brand.status === 'active';
  const fail = () => {
    setTransport('Не удалось связаться с сервером. Попробуйте ещё раз.');
    return false;
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setTransport('');
    form.post(base(brand) + '/domains', {
      preserveScroll: true,
      onSuccess: () => {
        setOpen(false);
        form.reset();
      },
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  const verify = (domain: BrandDomain) => {
    setTransport('');
    router.post(
      base(brand) + '/domains/' + domain.id + '/verify',
      {},
      {
        preserveScroll: true,
        onStart: () => setPending(domain.id),
        onFinish: () => setPending(null),
        onError: (errors) =>
          setTransport(Object.values(errors).join(' ') || 'Подтверждение домена пока не найдено.'),
        onNetworkError: fail,
        onHttpException: fail,
      },
    );
  };
  const deleteDomain = () => {
    if (!remove) return;
    setTransport('');
    router.delete(base(brand) + '/domains/' + remove.id, {
      preserveScroll: true,
      onStart: () => setPending(remove.id),
      onFinish: () => setPending(null),
      onSuccess: () => setRemove(null),
      onError: (errors) => setTransport(Object.values(errors).join(' ')),
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  return (
    <>
      <section className={panel + ' space-y-5'}>
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-bold">Домены бренда</h2>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-(--color-ink-muted)">
              Подтвердите владение доменами, которые будут использоваться для виджета и проверки
              видимости.
            </p>
          </div>
          <Button
            onClick={() => {
              setTransport('');
              setOpen(true);
            }}
            disabled={!canEdit}
          >
            <Plus size={18} aria-hidden="true" /> Добавить домен
          </Button>
        </header>
        {!networkChecksEnabled && (
          <p className="text-sm text-(--color-ink-muted)">Внешние проверки пока недоступны.</p>
        )}
        <FormErrors errors={{}} transport={transport} />
        {domains.length === 0 ? (
          <div className="rounded-xl border border-dashed border-(--color-control-border) px-5 py-12 text-center">
            <Globe size={32} className="mx-auto text-(--color-link)" aria-hidden="true" />
            <h3 className="mt-4 text-lg font-semibold">Домены ещё не добавлены</h3>
            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-(--color-ink-muted)">
              Добавьте домен, чтобы получить файл или DNS-запись для подтверждения.
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {domains.map((domain) => (
              <article
                key={domain.id}
                className="grid items-center gap-4 rounded-xl border border-(--color-border) p-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,.8fr)_minmax(0,1fr)]"
              >
                <div className="min-w-0">
                  <h3 className="break-all text-base font-semibold">{domain.host}</h3>
                  {domain.last_error && (
                    <p role="alert" className="mt-2 text-sm leading-6 text-(--color-danger-ink)">
                      {domain.last_error}
                    </p>
                  )}
                  {domain.last_checked_at && (
                    <p className="mt-2 text-sm text-(--color-ink-muted)">
                      Последняя проверка: {new Date(domain.last_checked_at).toLocaleString('ru-RU')}
                    </p>
                  )}
                  <p className="mt-1 text-sm text-(--color-ink-muted)">
                    {domain.purpose === 'widget' ? 'Виджет' : 'Виджет и видимость'}
                  </p>
                </div>
                <p
                  className={
                    'flex items-center gap-2 text-sm ' +
                    (domain.status === 'verified'
                      ? 'text-(--color-success)'
                      : 'text-(--color-ink-muted)')
                  }
                >
                  {domain.status === 'verified' ? (
                    <ShieldCheck size={18} className="shrink-0" aria-hidden="true" />
                  ) : (
                    <Info size={18} className="shrink-0" aria-hidden="true" />
                  )}
                  {domain.status === 'verified'
                    ? 'Подтверждён'
                    : domain.status === 'failed'
                      ? 'Проверка не пройдена'
                      : 'Ожидает проверки'}
                </p>
                <div className="text-sm text-(--color-ink-muted)">
                  <p>{domain.verification_method === 'dns' ? 'DNS TXT-запись' : 'Файл на сайте'}</p>
                  {domain.verified_at && (
                    <p className="mt-1">
                      {new Date(domain.verified_at).toLocaleDateString('ru-RU')}
                    </p>
                  )}
                </div>
                <div className="flex flex-wrap gap-2 xl:col-span-3">
                  <Button variant="secondary" onClick={() => setInstructions(domain)}>
                    Инструкция
                  </Button>
                  <Button
                    variant="secondary"
                    loading={pending === domain.id}
                    disabled={!networkChecksEnabled || !canEdit || pending !== null}
                    onClick={() => verify(domain)}
                  >
                    Проверить
                  </Button>
                  <Button
                    variant="ghost"
                    aria-label={'Удалить домен ' + domain.host}
                    disabled={!canEdit || pending !== null}
                    onClick={() => setRemove(domain)}
                  >
                    <Trash2 size={18} aria-hidden="true" />
                  </Button>
                </div>
              </article>
            ))}
          </div>
        )}
        <div className="flex items-start gap-3 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">
          <Info className="mt-1 shrink-0" size={18} aria-hidden="true" />
          <p>
            Для подтверждения добавьте проверочный файл на сайт или TXT-запись в DNS. Персональные
            значения доступны в инструкции каждого домена.
          </p>
        </div>
      </section>
      <Modal open={open} title="Добавить домен" onClose={() => setOpen(false)}>
        <form onSubmit={submit} className="space-y-5">
          <FormErrors errors={form.errors} transport={transport} />
          <Input
            label="Домен"
            value={form.data.host}
            onChange={(e) => form.setData('host', e.target.value)}
            error={form.errors.host}
            placeholder="example.ru"
            hint="Без протокола, порта и пути"
            required
          />
          <Select
            label="Назначение"
            value={form.data.purpose}
            onValueChange={(value) => form.setData('purpose', value)}
            options={[
              { value: 'widget_and_visibility', label: 'Виджет и видимость' },
              { value: 'widget', label: 'Виджет' },
            ]}
          />
          <Select
            label="Способ подтверждения"
            value={form.data.verification_method}
            onValueChange={(value) => form.setData('verification_method', value)}
            options={[
              { value: 'file', label: 'Файл на сайте' },
              { value: 'dns', label: 'DNS TXT-запись' },
            ]}
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={form.processing}>
              Добавить
            </Button>
          </div>
        </form>
      </Modal>
      <Modal
        open={instructions !== null}
        title="Подтверждение домена"
        onClose={() => setInstructions(null)}
      >
        {instructions && (
          <div className="space-y-5 text-sm">
            <p className="break-all font-semibold">{instructions.host}</p>
            {instructions.verification_method === 'file' ? (
              <>
                <p className="leading-6 text-(--color-ink-muted)">
                  Разместите текстовый файл по указанному пути. Его содержимое должно точно
                  совпадать с проверочным значением.
                </p>
                <Proof label="Путь к файлу" value={instructions.verification.file_path} />
                <Proof label="Содержимое файла" value={instructions.verification.file_content} />
              </>
            ) : (
              <>
                <p className="leading-6 text-(--color-ink-muted)">
                  Добавьте TXT-запись у вашего DNS-провайдера. После обновления DNS нажмите
                  «Проверить».
                </p>
                <Proof label="Имя TXT-записи" value={instructions.verification.dns_name} />
                <Proof label="Значение TXT-записи" value={instructions.verification.dns_value} />
              </>
            )}
            <Button variant="secondary" onClick={() => setInstructions(null)}>
              Понятно
            </Button>
          </div>
        )}
      </Modal>
      <Modal open={remove !== null} title="Удалить домен" onClose={() => setRemove(null)}>
        <p className="break-words text-sm leading-6">
          Удалить домен «{remove?.host}»? Для повторного добавления потребуется новое подтверждение.
        </p>
        <FormErrors errors={{}} transport={transport} />
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setRemove(null)}>
            Отмена
          </Button>
          <Button variant="danger" loading={pending !== null} onClick={deleteDomain}>
            Удалить
          </Button>
        </div>
      </Modal>
    </>
  );
}
function Proof({ label, value }: { label: string; value: string }) {
  const [message, setMessage] = useState('');
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setMessage('Скопировано');
    } catch {
      setMessage('Выделите и скопируйте значение вручную');
    }
  };
  return (
    <div>
      <p className="mb-2 font-semibold">{label}</p>
      <div className="rounded-lg border border-(--color-border) bg-(--color-surface-muted) p-3">
        <code className="block break-all whitespace-pre-wrap text-sm">{value}</code>
      </div>
      <button
        type="button"
        onClick={() => void copy()}
        className={
          'mt-2 inline-flex min-h-10 cursor-pointer items-center gap-2 rounded text-sm text-(--color-link) ' +
          focus
        }
      >
        <Copy size={16} aria-hidden="true" /> Копировать
      </button>
      {message && (
        <p role="status" className="mt-1 text-sm text-(--color-ink-muted)">
          {message}
        </p>
      )}
    </div>
  );
}
