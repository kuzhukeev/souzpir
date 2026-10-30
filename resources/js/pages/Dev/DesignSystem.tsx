import { Head } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { CircleCheck } from 'lucide-react';
import {
  Avatar,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  Checkbox,
  Chip,
  CodeBlock,
  Combobox,
  DataTable,
  DatePicker,
  Drawer,
  EmptyState,
  FileDrop,
  FilterBar,
  Input,
  KpiCard,
  Modal,
  Pagination,
  ProgressBar,
  Radio,
  SegmentedControl,
  Select,
  Skeleton,
  StepProgress,
  Switch,
  Tabs,
  Textarea,
  Timeline,
  TimePicker,
  Toast,
  Tooltip,
} from '@/components/ui';

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-4 rounded-(--radius-card) bg-(--color-surface) p-5 shadow-(--shadow-card)">
      <h2 className="text-xl font-bold">{title}</h2>
      {children}
    </section>
  );
}
export default function DesignSystem() {
  const [tab, setTab] = useState('overview');
  const [mode, setMode] = useState('auto');
  const [active, setActive] = useState(true);
  const [chip, setChip] = useState(false);
  const [modal, setModal] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState(true);
  const [files, setFiles] = useState<File[]>([]);
  const [theme, setTheme] = useState('neutral');
  const changeTheme = (value: string) => {
    setTheme(value);
    document.documentElement.dataset.theme = value;
  };
  return (
    <>
      <Head title="Дизайн-система" />
      <main className="min-h-dvh bg-(--color-bg) px-4 py-8 text-(--color-ink) sm:px-8">
        <div className="mx-auto max-w-6xl space-y-6">
          <Breadcrumbs
            items={[{ label: 'Главная', href: '/preview/attention' }, { label: 'Дизайн-система' }]}
          />
          <header className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <h1 className="text-3xl font-bold">Дизайн-система</h1>
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                Локальная страница проверки компонентов. Данные демонстрационные.
              </p>
            </div>
            <Select
              label="Тема"
              value={theme}
              onValueChange={changeTheme}
              options={[
                { value: 'neutral', label: 'Нейтральная' },
                { value: 'pyro', label: 'Пиротехника' },
                { value: 'confectionery', label: 'Кондитерская' },
              ]}
            />
          </header>
          <Section title="Кнопки и формы">
            <div className="flex flex-wrap gap-3">
              <Button>Продолжить</Button>
              <Button variant="secondary">Отмена</Button>
              <Button variant="ghost">Подробнее</Button>
              <Button variant="danger">Удалить</Button>
              <Button loading>Сохранение</Button>
              <Button disabled>Недоступно</Button>
              <Chip selected={chip} onClick={() => setChip(!chip)}>
                Тег
              </Chip>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <Input label="Название" placeholder="Введите название" />
              <Input label="Поле с ошибкой" error="Проверьте значение" />
              <Textarea label="Описание" rows={3} hint="Необязательное поле" />
              <Select
                label="Статус"
                defaultValue="draft"
                options={[
                  { value: 'draft', label: 'Черновик' },
                  { value: 'active', label: 'Активен' },
                ]}
              />
              <Combobox label="Город" options={['Москва', 'Казань']} />
              <DatePicker label="Дата" />
              <TimePicker label="Время" />
            </div>
            <div className="flex flex-wrap gap-5">
              <Checkbox label="Получать уведомления" />
              <Radio name="example" label="Основной вариант" defaultChecked />
              <Radio name="example" label="Другой вариант" />
              <Switch label="Включено" checked={active} onChange={setActive} />
            </div>
          </Section>
          <Section title="Навигация">
            <Tabs
              label="Разделы"
              value={tab}
              onChange={setTab}
              tabs={[
                { id: 'overview', label: 'Обзор', content: 'Содержимое обзора' },
                { id: 'details', label: 'Детали', content: 'Содержимое деталей' },
                { id: 'later', label: 'Скоро', content: '', disabled: true },
              ]}
            />
            <SegmentedControl
              label="Режим"
              value={mode}
              onChange={setMode}
              options={[
                { value: 'auto', label: 'Автономно' },
                { value: 'manual', label: 'Вручную' },
              ]}
            />
            <StepProgress steps={['Данные', 'Проверка', 'Готово']} current={1} />
            <Pagination page={page} pages={3} onChange={setPage} />
          </Section>
          <Section title="Карточки и статусы">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <KpiCard label="Диалоги" value="12" delta="За неделю" />
              <Card>
                <h3 className="font-semibold">Карточка</h3>
                <p className="mt-2 text-sm">Содержимое карточки</p>
              </Card>
              <div className="space-y-3">
                <Badge tone="success">Готово</Badge> <Badge tone="warning">Ожидает</Badge>{' '}
                <Badge tone="danger">Ошибка</Badge>
                <ProgressBar label="Прогресс" value={45} />
                <Skeleton />
              </div>
            </div>
            <EmptyState
              title="Пока пусто"
              description="Здесь появятся данные."
              icon={<CircleCheck size={36} />}
              action={<Button>Добавить</Button>}
            />
          </Section>
          <Section title="Таблица и фильтры">
            <FilterBar>
              <Input label="Поиск" placeholder="Найти" />
              <Button variant="secondary">Сбросить</Button>
            </FilterBar>
            <DataTable
              label="Пример таблицы"
              rows={[
                { id: '1', name: 'Первый', status: 'Активен' },
                { id: '2', name: 'Второй', status: 'Черновик' },
              ]}
              columns={[
                { key: 'name', label: 'Название', sortable: true },
                { key: 'status', label: 'Статус', sortable: true },
              ]}
            />
          </Section>
          <Section title="Диалоги и уведомления">
            <div className="flex flex-wrap gap-3">
              <Button variant="secondary" onClick={() => setModal(true)}>
                Открыть окно
              </Button>
              <Button variant="secondary" onClick={() => setDrawer(true)}>
                Открыть панель
              </Button>
              <Tooltip label="Дополнительная информация">Подсказка</Tooltip>
            </div>
            {notice && <Toast message="Пример уведомления" onClose={() => setNotice(false)} />}
            <Modal open={modal} title="Подтверждение" onClose={() => setModal(false)}>
              <p>Пример диалогового окна.</p>
              <Button className="mt-4" onClick={() => setModal(false)}>
                Понятно
              </Button>
            </Modal>
            <Drawer open={drawer} title="Панель" onClose={() => setDrawer(false)}>
              <p>Содержимое боковой панели.</p>
            </Drawer>
          </Section>
          <Section title="Дополнительные компоненты">
            <div className="flex items-center gap-3">
              <Avatar name="Иван Петров" />
              <span className="text-sm">Иван Петров</span>
            </div>
            <Timeline
              entries={[
                {
                  id: '1',
                  title: 'Сообщение',
                  time: 'Сегодня',
                  detail: 'Пример сообщения',
                  metadata: 'Guard: пройден',
                },
              ]}
            />
            <CodeBlock code={'<script src="/widget.js"></script>'} />
            <FileDrop label="Выберите или перетащите файл" onFiles={setFiles} multiple />
            <p className="text-sm" aria-live="polite">
              Выбрано файлов: {files.length}
            </p>
          </Section>
        </div>
      </main>
    </>
  );
}
