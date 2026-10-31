import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { Button, Input, Modal } from '@/components/ui';
import { FormErrors, panel } from './shared';
import type { BrandSettings } from '@/types/Shell/Brands';
export function ArchiveBrand({ brand, editable }: { brand: BrandSettings; editable: boolean }) {
  const [open, setOpen] = useState(false);
  const [transport, setTransport] = useState('');
  const form = useForm({ confirm_name: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setTransport('');
    form.patch('/brands/' + brand.id + '/archive', {
      preserveScroll: true,
      onSuccess: () => setOpen(false),
      onNetworkError: () => {
        setTransport('Не удалось связаться с сервером.');
        return false;
      },
      onHttpException: () => {
        setTransport('Не удалось архивировать бренд.');
        return false;
      },
    });
  };
  return (
    <>
      <section className={panel + ' max-w-3xl'}>
        <Archive size={28} className="text-(--color-danger-ink)" aria-hidden="true" />
        <h2 className="mt-4 text-xl font-bold">Архивировать бренд</h2>
        <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
          Бренд станет недоступен в кабинете. Его сохранённые данные останутся. Последний активный
          бренд архивировать нельзя.
        </p>
        <Button
          className="mt-5"
          variant="danger"
          onClick={() => setOpen(true)}
          disabled={!editable || brand.status !== 'active'}
        >
          Архивировать бренд
        </Button>
      </section>
      <Modal open={open} title="Архивировать бренд" onClose={() => setOpen(false)}>
        <form onSubmit={submit} className="space-y-5">
          <p className="break-words text-sm leading-6">
            Для подтверждения введите название «{brand.name}».
          </p>
          <FormErrors errors={form.errors} transport={transport} />
          <Input
            label="Название бренда для подтверждения"
            value={form.data.confirm_name}
            onChange={(e) => form.setData('confirm_name', e.target.value)}
            error={form.errors.confirm_name}
            autoComplete="off"
            required
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Отмена
            </Button>
            <Button type="submit" variant="danger" loading={form.processing}>
              Архивировать
            </Button>
          </div>
        </form>
      </Modal>
    </>
  );
}
