import { useEffect, useState, type FormEvent } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Eye, MessageCircle, Sparkles } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { AsideInfo, Hint, OnboardingFrame, focusClass } from '@/features/Shell/Onboarding/OnboardingFrame';
import type { OnboardingInitial, OnboardingValues } from '@/types/Shell/Onboarding';
import type { SharedPropsV1 } from '@/types/generated/data';

const titles = ['Расскажите о компании', 'Сайт и бренд', 'Выберите модули', 'Первый шаг'];
const subtitles = ['Несколько шагов помогут подготовить кабинет к работе', 'Добавьте сайт и выберите оформление кабинета', 'Выберите модули, которые планируете настроить', 'Начните работу с выбранными модулями'];
const fieldStep: Partial<Record<keyof OnboardingValues, number>> = { organization_name: 1, industry: 1, region: 1, timezone: 1, website: 2, brand_name: 2, alias: 2, theme: 2, seller: 3, vision: 3 };
const themes: { value: OnboardingValues['theme']; name: string; description: string; color: string }[] = [
  { value: 'pyro', name: 'Пиротехника', description: 'Тёмно-синий и оранжевый', color: '#1f2a44' },
  { value: 'neutral', name: 'Нейтральная', description: 'Фиолетовый', color: '#6425f5' },
  { value: 'confectionery', name: 'Кондитерская', description: 'Зелёный и золотистый', color: '#2f5d3a' },
];

export default function Start({ initial }: { initial: OnboardingInitial }) {
  return <StartView key={initial.completed ? 'completed' : 'pending'} initial={initial} />;
}

function StartView({ initial }: { initial: OnboardingInitial }) {
  const { completed, already_configured, logo_url, ...initialValues } = initial;
  const [step, setStep] = useState(() => completed || already_configured ? 4 : Math.min(4, Math.max(1, initial.step || 1)));
  const [notice, setNotice] = useState('');
  const [transportError, setTransportError] = useState('');
  const [localError, setLocalError] = useState('');
  const [logoPending, setLogoPending] = useState(false);
  const [logoError, setLogoError] = useState('');
  const [logoNotice, setLogoNotice] = useState('');
  const form = useForm<OnboardingValues>(initialValues);
  const { props } = usePage<SharedPropsV1 & Record<string, unknown>>();
  useEffect(() => {
    const sync = () => router.reload({ only: ['initial', 'modules'] });
    const onStorage = (event: StorageEvent) => { if (event.key === 'souzpir.onboarding.completed') sync(); };
    const onVisibility = () => { if (document.visibilityState === 'visible') sync(); };
    window.addEventListener('storage', onStorage);
    document.addEventListener('visibilitychange', onVisibility);
    return () => { window.removeEventListener('storage', onStorage); document.removeEventListener('visibilitychange', onVisibility); };
  }, []);
  const routeExists = (uri: string) => Object.values(props.ziggy?.routes ?? {}).some((route) => route.uri === uri && route.methods.includes('GET'));
  if (already_configured && !completed) return <><Head title="Первый запуск" /><main className="grid min-h-dvh place-items-center bg-(--color-bg) p-4 text-(--color-ink)"><section className="w-full max-w-xl rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 shadow-(--shadow-card) sm:p-8"><h1 className="text-2xl font-bold">Первичная настройка недоступна</h1><p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">У вашей компании уже есть бренд. Управление брендами доступно в кабинете.</p>{<a href={routeExists('company') ? '/company' : '/auth/ready'} className={`mt-5 inline-flex min-h-10 cursor-pointer items-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white ${focusClass}`}>{routeExists('company') ? 'Компания и бренды' : 'Перейти в кабинет'}</a>}</section></main></>;
  const fail = (message: string) => { setTransportError(message); return false; };
  const uploadLogo = (file: File | undefined) => {
    if (!file || logoPending || completed) return;
    setLogoError(''); setLogoNotice('');
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 1024 * 1024) { setLogoError('Выберите PNG, JPEG или WebP размером до 1 МБ. SVG пока не поддерживается.'); return; }
    router.post('/start/logo', { logo: file }, {
      forceFormData: true, preserveState: true, preserveScroll: true,
      onStart: () => setLogoPending(true), onFinish: () => setLogoPending(false),
      onSuccess: () => setLogoNotice('Логотип сохранён.'),
      onError: (errors) => setLogoError(errors.logo ?? 'Не удалось сохранить логотип.'),
      onNetworkError: () => { setLogoError('Не удалось связаться с сервером.'); return false; },
      onHttpException: () => { setLogoError('Не удалось сохранить логотип.'); return false; },
    });
  };
  const save = (next: number, final = false) => {
    if (form.processing || logoPending || completed) return;
    setTransportError(''); setLocalError(''); setNotice('');
    if (next > step || final) {
      if (step === 1 && !form.data.organization_name.trim()) { setLocalError('Укажите название компании.'); return; }
      if (step === 2 && !form.data.brand_name.trim()) { setLocalError('Укажите название бренда.'); return; }
      if (step === 3 && !form.data.seller && !form.data.vision) { setLocalError('Выберите хотя бы один модуль.'); return; }
    }
    form.transform((data) => ({ ...data, step: next }));
    form.post(final ? '/start' : '/start/draft', {
      preserveScroll: true,
      preserveState: !final,
      onSuccess: (page) => { if (!final) { form.setData('step', next); setStep(next); setNotice('Черновик сохранён'); } else if ((page.props.initial as OnboardingInitial | undefined)?.completed) { try { localStorage.setItem('souzpir.onboarding.completed', String(Date.now()) + Math.random()); } catch { /* Visibility refresh still checks server state. */ } } },
      onError: (errors) => { const invalid = Object.keys(errors).find((key) => fieldStep[key as keyof OnboardingValues]); if (invalid) setStep(fieldStep[invalid as keyof OnboardingValues] ?? step); },
      onNetworkError: () => fail('Не удалось связаться с сервером. Проверьте подключение и попробуйте ещё раз.'),
      onHttpException: () => fail('Не удалось сохранить настройки. Попробуйте ещё раз.'),
    });
  };
  const submit = (event: FormEvent) => { event.preventDefault(); save(step === 4 ? 4 : step + 1, step === 4); };
  const title = titles[step - 1];
  const subtitle = subtitles[step - 1];
  const errors = form.errors;
  const genericError = (errors as Record<string, string | undefined>).onboarding;
  const common = 'text-sm leading-6 text-(--color-ink-muted)';
  const aside = step === 1 ? <><AsideInfo title="Зачем это нужно"><p>Название и регион помогут настроить рабочее пространство для вашей компании.</p></AsideInfo><Hint>Оформление кабинета можно выбрать на следующем шаге и изменить позже.</Hint></> : step === 2 ? <><AsideInfo title="Предпросмотр меню"><p>Так будет выглядеть кабинет с выбранным оформлением.</p></AsideInfo><div className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface-muted) p-4"><div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-lg font-bold text-white" style={{ backgroundColor: themes.find((theme) => theme.value === form.data.theme)?.color }}>{(form.data.brand_name.trim().slice(0, 1) || 'Б').toUpperCase()}</span><div className="min-w-0"><p className="break-words text-sm font-semibold">{form.data.brand_name.trim() || 'Название бренда'}</p><p className="break-words text-sm text-(--color-ink-muted)">{form.data.industry || 'Ваш кабинет'}</p></div></div><div className="mt-4 rounded-lg bg-(--color-nav-active) px-3 py-2 text-sm font-semibold text-white">Главная</div><p className="mt-3 text-sm text-(--color-ink-muted)">Модули · Кабинет</p></div><Hint>Название и оформление можно изменить позже.</Hint></> : step === 3 ? <><AsideInfo title="Что можно настроить"><p>Выберите Видение, ИИ-продавца или оба модуля для дальнейшей настройки.</p></AsideInfo><Hint>Выбор сохраняется как предпочтение. Доступность и подключение зависят от тарифа.</Hint></> : <><AsideInfo title="Что дальше?"><p>Завершите первичную настройку. Переходы появятся, когда модули будут доступны.</p></AsideInfo><Hint>Вы сможете вернуться к настройкам позже.</Hint></>;
  const footer = <><Button variant="secondary" disabled={step === 1 || form.processing || logoPending || completed} onClick={() => save(step - 1)}><ArrowLeft size={17} aria-hidden="true" /> Назад</Button><div className="flex flex-wrap items-center gap-4"><span role="status" className="text-sm text-(--color-success)">{form.processing ? 'Сохраняем…' : notice}</span>{completed && <a href="/auth/ready" className={`inline-flex min-h-10 cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white ${focusClass}`}>Перейти в кабинет</a>}{!completed && <Button type="submit" form="onboarding-form" loading={form.processing || logoPending}>{step === 4 ? 'Завершить настройку' : 'Сохранить и продолжить'} <ArrowRight size={17} aria-hidden="true" /></Button>}</div></>;
  return <><Head title="Первый запуск" /><OnboardingFrame step={step} title={title} subtitle={subtitle} saving={form.processing || completed} onSave={() => save(step)} readOnly={completed || already_configured} aside={aside} footer={already_configured && !completed ? null : footer}>
    <form id="onboarding-form" onSubmit={submit} noValidate>
      {(!already_configured || completed) && <fieldset disabled={form.processing || completed}>
      {(transportError || localError) && <div role="alert" className="mb-5 rounded-xl bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{transportError || localError}</div>}
      {Object.keys(errors).length > 0 && <p role="alert" className="mb-5 text-sm text-(--color-danger-ink)">{genericError || 'Проверьте отмеченные поля и попробуйте ещё раз.'}</p>}
      {step === 1 && <><h2 className="text-xl font-bold">Данные компании</h2><p className={`mt-1 ${common}`}>Их можно изменить позже в настройках.</p><div className="mt-5 space-y-4">
        <Input label="Название компании" value={form.data.organization_name} maxLength={255} required disabled={completed} onChange={(e) => form.setData('organization_name', e.target.value)} error={errors.organization_name} />
        <Input label="Сфера деятельности" value={form.data.industry} maxLength={255} disabled={completed} onChange={(e) => form.setData('industry', e.target.value)} error={errors.industry} placeholder="Например, пиротехника" />
        <Input label="Регион" value={form.data.region} maxLength={255} disabled={completed} onChange={(e) => form.setData('region', e.target.value)} error={errors.region} placeholder="Например, Москва" />
        <Input label="Часовой пояс" value={form.data.timezone} maxLength={255} disabled={completed} onChange={(e) => form.setData('timezone', e.target.value)} error={errors.timezone} placeholder="Europe/Moscow" hint="Укажите часовой пояс, например Europe/Moscow." />
      </div></>}
      {step === 2 && <><h2 className="text-xl font-bold">Данные бренда</h2><p className={`mt-1 ${common}`}>Это название увидят участники вашей команды.</p><div className="mt-5 space-y-4">
        <Input label="Адрес сайта" type="url" value={form.data.website} maxLength={2048} disabled={completed} onChange={(e) => form.setData('website', e.target.value)} error={errors.website} placeholder="https://example.ru" />
        <Input label="Название бренда" value={form.data.brand_name} maxLength={255} required disabled={completed} onChange={(e) => form.setData('brand_name', e.target.value)} error={errors.brand_name} />
        <Input label="Как клиенты вас называют" value={form.data.alias} maxLength={255} disabled={completed} onChange={(e) => form.setData('alias', e.target.value)} error={errors.alias} placeholder="Например, Союз Пир" /><div className="space-y-2"><label htmlFor="onboarding-logo" className="block text-sm font-semibold">Логотип</label><p className="text-sm text-(--color-ink-muted)">PNG, JPEG или WebP до 1 МБ. SVG пока не поддерживается.</p>{logo_url && <img src={logo_url} alt="Сохранённый логотип бренда" className="max-h-24 max-w-48 rounded-lg object-contain" />}{!completed && <input id="onboarding-logo" type="file" accept="image/png,image/jpeg,image/webp" disabled={logoPending || form.processing} className={`block max-w-full cursor-pointer text-sm file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-(--color-surface-muted) file:px-3 file:py-2 file:text-sm ${focusClass}`} onChange={(event) => { uploadLogo(event.target.files?.[0]); event.currentTarget.value = ''; }} />}{logoPending && <p role="status" className="text-sm">Загружаем логотип…</p>}{logoNotice && <p role="status" className="text-sm text-(--color-success)">{logoNotice}</p>}{logoError && <p role="alert" className="text-sm text-(--color-danger-ink)">{logoError}</p>}</div>
        <fieldset><legend className="mb-2 text-sm font-semibold">Оформление кабинета</legend><div className="grid gap-3 sm:grid-cols-2">{themes.map((theme) => <label key={theme.value} className={`flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border p-3 ${form.data.theme === theme.value ? 'border-(--color-control-border) bg-(--color-info-soft)' : 'border-(--color-border)'} ${completed ? 'cursor-default' : ''}`}><input className={`size-4 accent-(--color-link) ${focusClass}`} type="radio" name="theme" value={theme.value} checked={form.data.theme === theme.value} disabled={completed} onChange={() => form.setData('theme', theme.value)} /><span aria-hidden="true" className="size-8 shrink-0 rounded-lg" style={{ backgroundColor: theme.color }} /><span><span className="block text-sm font-semibold">{theme.name}</span><span className="block text-sm text-(--color-ink-muted)">{theme.description}</span></span></label>)}</div>{errors.theme && <p className="mt-2 text-sm text-(--color-danger-ink)">{errors.theme}</p>}</fieldset>
      </div></>}
      {step === 3 && <><h2 className="text-xl font-bold">Модули для настройки</h2><p className={`mt-1 ${common}`}>Можно выбрать один или оба модуля для дальнейшей настройки.</p><div className="mt-5 grid gap-4 sm:grid-cols-2">{([['vision', 'Видение', 'Узнайте, как нейросети видят ваш бренд, и найдите возможности для роста.', Eye], ['seller', 'ИИ-продавец', 'Помогает отвечать клиентам, собирать контакты и передавать диалоги менеджеру.', MessageCircle]] as const).map(([key, label, description, Icon]) => <label key={key} className={`flex min-h-52 cursor-pointer flex-col rounded-xl border p-5 ${form.data[key] ? 'border-(--color-control-border) bg-(--color-info-soft)' : 'border-(--color-border)'} ${completed ? 'cursor-default' : ''}`}><span className="flex items-center justify-between gap-3"><span className="grid size-10 place-items-center rounded-xl bg-(--color-surface) text-(--color-link)"><Icon size={21} aria-hidden="true" /></span><input type="checkbox" checked={form.data[key]} disabled={completed} onChange={(e) => form.setData(key, e.target.checked)} className={`size-5 cursor-pointer accent-(--color-action) ${focusClass}`} /></span><span className="mt-4 text-base font-bold">{label}</span><span className={`mt-2 ${common}`}>{description}</span></label>)}</div><div className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm text-(--color-success)"><Check size={17} aria-hidden="true" className="mr-2 inline" />Выбрано для настройки: {Number(form.data.vision) + Number(form.data.seller)}</div>{(errors.vision || errors.seller) && <p className="mt-2 text-sm text-(--color-danger-ink)">{errors.vision || errors.seller}</p>}</>}
      {step === 4 && <><h2 className="text-xl font-bold">{completed ? 'Настройка завершена' : 'Начните работу с модулями'}</h2><p className={`mt-1 ${common}`}>{completed ? 'Данные компании и бренда сохранены.' : 'Переходы появятся, когда выбранные модули будут доступны.'}</p><div className="mt-5 grid gap-4 sm:grid-cols-2">{form.data.vision && <ModuleCard title="Видение" description="Начните с анкеты о компании и проверьте видимость бренда." icon={<Eye size={24} />} href={completed && props.modules?.vision === true && routeExists('vision/quiz/1') ? '/vision/quiz/1' : undefined} action="Начать анкету" />}{form.data.seller && <ModuleCard title="ИИ-продавец" description="Добавьте сведения о компании и подготовьте ответы для клиентов." icon={<MessageCircle size={24} />} href={completed && props.modules?.seller === true && routeExists('seller/knowledge') ? '/seller/knowledge' : undefined} action="Открыть базу знаний" />}{!form.data.vision && !form.data.seller && <p className={common}>Вернитесь к выбору модулей, чтобы выбрать нужные возможности.</p>}</div></>}
      </fieldset>}
    </form>
  </OnboardingFrame></>;
}

function ModuleCard({ title, description, icon, href, action }: { title: string; description: string; icon: React.ReactNode; href?: string; action: string }) {
  return <div className="flex min-h-52 flex-col rounded-xl border border-(--color-border) p-5"><span className="grid size-12 place-items-center rounded-xl bg-(--color-success-soft) text-(--color-success)">{icon}</span><h3 className="mt-4 text-base font-bold">{title}</h3><p className="mt-2 flex-1 text-sm leading-6 text-(--color-ink-muted)">{description}</p>{href ? <a href={href} className={`mt-5 inline-flex min-h-10 cursor-pointer items-center justify-center gap-2 rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white ${focusClass}`}>{action} <ArrowRight size={16} /></a> : <p className="mt-5 flex items-center gap-2 text-sm text-(--color-ink-muted)"><Sparkles size={16} aria-hidden="true" /> Скоро</p>}</div>;
}
