import { useId, useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, CircleAlert, Eye, EyeOff, LoaderCircle } from 'lucide-react';

export type LoginState = 'default' | 'invalid' | 'locked' | 'mfa' | 'loading';

type LoginProps = {
  state: LoginState;
  onStateChange: (state: LoginState) => void;
  onNotice: (text: string) => void;
};

const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--color-focus)';
const field =
  'h-14 w-full min-w-0 rounded-(--radius-control) border border-(--color-control-border) bg-(--color-surface) px-4 text-base text-(--color-ink) placeholder:text-(--color-ink-muted) focus:border-(--color-focus) focus:outline-2 focus:outline-offset-2 focus:outline-(--color-focus) disabled:cursor-wait disabled:bg-(--color-surface-muted) sm:px-5 sm:text-lg';
const submit = `flex h-14 w-full items-center justify-center gap-2 rounded-(--radius-control) bg-(--color-action) px-4 text-lg font-bold text-(--color-on-action) shadow-sm transition-colors hover:bg-(--color-action-hover) disabled:cursor-not-allowed disabled:bg-(--color-disabled) disabled:text-(--color-on-disabled) motion-reduce:transition-none ${focus}`;
const link = `rounded-sm font-medium text-(--color-link) underline-offset-4 hover:underline ${focus}`;

function EyonLogo() {
  return (
    <div role="img" aria-label="EYON" className="relative mb-8 flex items-center gap-3 select-none">
      <svg
        aria-hidden="true"
        viewBox="0 0 48 48"
        className="size-10 shrink-0 sm:size-12"
        fill="none"
      >
        <rect width="48" height="48" rx="13" fill="var(--color-brand-primary)" />
        <path
          d="M12 24s4.8-8 12-8 12 8 12 8-4.8 8-12 8-12-8-12-8Z"
          stroke="var(--color-on-brand-primary)"
          strokeWidth="2.6"
          strokeLinejoin="round"
        />
        <circle cx="24" cy="24" r="3.1" stroke="var(--color-on-brand-primary)" strokeWidth="2.6" />
      </svg>
      <span
        aria-hidden="true"
        className="text-[36px] leading-none font-extrabold tracking-[-1.8px] text-(--color-ink) sm:text-[44px]"
      >
        eyon<span className="text-(--color-brand-primary)">.</span>
      </span>
    </div>
  );
}

function LoginForm({ state, onStateChange, onNotice }: LoginProps) {
  const id = useId();
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);
  const codeRef = useRef<HTMLInputElement>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [visible, setVisible] = useState(false);
  const [remember, setRemember] = useState(false);
  const [errors, setErrors] = useState<{ email?: string; password?: string; code?: string }>({});
  const loading = state === 'loading';
  const locked = state === 'locked';
  const mfa = state === 'mfa';
  const heading = mfa
    ? 'Двухфакторная проверка'
    : state === 'invalid'
      ? 'Неверные данные'
      : locked
        ? 'После 5 попыток'
        : 'Вход в кабинет';
  const feedbackId = `${id}-feedback`;

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loading || locked) return;
    if (mfa) {
      if (!/^\d{6}$/.test(code)) {
        setErrors({ code: 'Введите 6 цифр кода.' });
        codeRef.current?.focus();
        return;
      }
      setErrors({});
      setCode('');
      onNotice('Предпросмотр: код заполнен, но не проверяется. Вход в аккаунт не выполняется.');
      return;
    }
    const nextErrors = {
      email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())
        ? undefined
        : 'Введите корректный адрес электронной почты.',
      password: password.length ? undefined : 'Введите пароль.',
    };
    setErrors(nextErrors);
    if (nextErrors.email || nextErrors.password) {
      (nextErrors.email ? emailRef : passwordRef).current?.focus();
      return;
    }
    setPassword('');
    setVisible(false);
    onNotice(
      'Предпросмотр: поля заполнены. Вход не выполняется, данные никуда не отправляются; пароль очищен.',
    );
  }

  return (
    <section
      aria-labelledby={`${id}-title`}
      className="relative w-full max-w-[530px] rounded-(--radius-card) bg-(--color-surface) p-6 shadow-(--shadow-card) sm:p-11"
    >
      <h1
        id={`${id}-title`}
        className={`text-[26px] leading-8 font-bold tracking-[-1px] ${mfa ? 'sm:text-[30px] sm:leading-10' : 'sm:text-[36px] sm:leading-11'}`}
      >
        {heading}
      </h1>
      {mfa ? (
        <div className="mt-8">
          <h2 className="text-xl leading-7 font-bold sm:text-2xl sm:leading-8">
            Подтверждение входа
          </h2>
          <p className="mt-2 text-base leading-6 text-(--color-ink-muted) sm:text-lg">
            Введите код из приложения
          </p>
        </div>
      ) : (
        <p className="mt-1 text-base leading-6 text-(--color-ink-muted) sm:text-lg">
          Рады видеть вас снова
        </p>
      )}

      <form
        onSubmit={handleSubmit}
        noValidate
        autoComplete="off"
        aria-busy={loading}
        className="mt-6 sm:mt-8"
      >
        {mfa ? (
          <div>
            <label htmlFor={`${id}-code`} className="mb-2 block text-base leading-5 font-semibold">
              Код подтверждения
            </label>
            <input
              ref={codeRef}
              id={`${id}-code`}
              name="preview_code"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              maxLength={6}
              value={code}
              onChange={(event) => {
                setCode(event.target.value.replace(/\D/g, '').slice(0, 6));
                setErrors({});
              }}
              placeholder="000 000"
              aria-invalid={Boolean(errors.code)}
              aria-describedby={errors.code ? `${id}-code-error` : undefined}
              className={`${field} tracking-[.18em] tabular-nums`}
            />
            {errors.code && (
              <p
                id={`${id}-code-error`}
                role="alert"
                className="mt-2 text-sm text-(--color-danger-ink)"
              >
                {errors.code}
              </p>
            )}
            <button type="submit" className={`${submit} mt-10`}>
              Подтвердить
            </button>
            <button
              type="button"
              onClick={() => onStateChange('default')}
              className={`${link} mx-auto mt-7 flex min-h-10 items-center justify-center gap-2 text-base`}
            >
              <ArrowLeft size={20} aria-hidden="true" />
              Назад ко входу
            </button>
          </div>
        ) : (
          <>
            <div>
              <label
                htmlFor={`${id}-email`}
                className="mb-2 block text-base leading-5 font-semibold"
              >
                Электронная почта
              </label>
              <input
                ref={emailRef}
                id={`${id}-email`}
                name="preview_email"
                type="email"
                inputMode="email"
                autoComplete="off"
                spellCheck={false}
                value={email}
                onChange={(event) => {
                  setEmail(event.target.value);
                  setErrors((previous) => ({ ...previous, email: undefined }));
                }}
                disabled={loading}
                placeholder="name@company.ru"
                aria-invalid={Boolean(errors.email) || state === 'invalid'}
                aria-describedby={
                  errors.email ? `${id}-email-error` : state === 'invalid' ? feedbackId : undefined
                }
                className={field}
              />
              {errors.email && (
                <p
                  id={`${id}-email-error`}
                  role="alert"
                  className="mt-2 text-sm text-(--color-danger-ink)"
                >
                  {errors.email}
                </p>
              )}
            </div>
            <div className="mt-4 sm:mt-6">
              <label
                htmlFor={`${id}-password`}
                className="mb-2 block text-base leading-5 font-semibold"
              >
                Пароль
              </label>
              <div className="relative">
                <input
                  ref={passwordRef}
                  id={`${id}-password`}
                  name="preview_password"
                  type={visible ? 'text' : 'password'}
                  autoComplete="off"
                  value={password}
                  onChange={(event) => {
                    setPassword(event.target.value);
                    setErrors((previous) => ({ ...previous, password: undefined }));
                  }}
                  disabled={loading}
                  placeholder="Введите пароль"
                  aria-invalid={Boolean(errors.password) || state === 'invalid'}
                  aria-describedby={
                    errors.password
                      ? `${id}-password-error`
                      : state === 'invalid'
                        ? feedbackId
                        : undefined
                  }
                  className={`${field} pr-14 sm:pr-14`}
                />
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => setVisible((previous) => !previous)}
                  aria-pressed={visible}
                  aria-label={visible ? 'Скрыть пароль' : 'Показать пароль'}
                  className={`absolute inset-y-1 right-1 flex w-12 items-center justify-center rounded-lg text-(--color-icon) disabled:cursor-wait ${focus}`}
                >
                  {visible ? (
                    <EyeOff size={22} strokeWidth={1.75} aria-hidden="true" />
                  ) : (
                    <Eye size={22} strokeWidth={1.75} aria-hidden="true" />
                  )}
                </button>
              </div>
              {errors.password && (
                <p
                  id={`${id}-password-error`}
                  role="alert"
                  className="mt-2 text-sm text-(--color-danger-ink)"
                >
                  {errors.password}
                </p>
              )}
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 text-sm leading-5 sm:mt-5">
              <label className="flex min-h-8 cursor-pointer items-center gap-2.5 text-(--color-ink-muted)">
                <input
                  type="checkbox"
                  checked={remember}
                  onChange={(event) => setRemember(event.target.checked)}
                  disabled={loading}
                  className={`size-5 shrink-0 accent-(--color-action) ${focus}`}
                />
                Запомнить меня
              </label>
              <button
                type="button"
                onClick={() =>
                  onNotice(
                    'Восстановление пароля пока не подключено. Это локальный предпросмотр формы.',
                  )
                }
                className={`${link} min-h-8`}
              >
                Забыли пароль?
              </button>
            </div>
            <button type="submit" disabled={locked || loading} className={`${submit} mt-5 sm:mt-7`}>
              {loading && (
                <LoaderCircle
                  aria-hidden="true"
                  size={21}
                  className="animate-spin motion-reduce:animate-none"
                />
              )}
              {loading ? 'Загрузка…' : 'Войти'}
            </button>
            {state === 'invalid' && (
              <p
                id={feedbackId}
                role="alert"
                className="mt-4 flex items-start gap-3 rounded-lg bg-(--color-danger-soft) px-4 py-3 text-sm leading-5 text-(--color-danger-ink)"
              >
                <CircleAlert size={20} className="shrink-0" aria-hidden="true" />
                Неверная почта или пароль
              </p>
            )}
            {locked && (
              <p
                id={feedbackId}
                role="status"
                className="mt-4 flex items-start gap-3 rounded-lg bg-(--color-warning-soft) px-4 py-3 text-sm leading-5 text-(--color-warning-ink)"
              >
                <CircleAlert size={20} className="shrink-0" aria-hidden="true" />
                Попробуйте через 10 минут
              </p>
            )}
            {loading && (
              <span role="status" className="sr-only">
                Демонстрация состояния загрузки
              </span>
            )}
            {(state === 'default' || loading) && (
              <p className="mt-5 border-t border-(--color-border) pt-5 text-center text-sm leading-5 text-(--color-ink-muted) sm:mt-8 sm:pt-7 sm:text-base sm:leading-6">
                Нет аккаунта?{' '}
                <button
                  type="button"
                  onClick={() =>
                    onNotice(
                      'Регистрация появится на отдельном этапе. Сейчас открыт предпросмотр без создания аккаунта.',
                    )
                  }
                  className={link}
                >
                  Зарегистрироваться
                </button>
              </p>
            )}
          </>
        )}
      </form>
    </section>
  );
}

export default function LoginPreview(props: LoginProps) {
  return (
    <main className="relative isolate flex min-h-[calc(100dvh-45px)] flex-col items-center overflow-hidden bg-(--color-bg) px-4 py-8 font-(family-name:--font-sans) text-(--color-ink) sm:px-6 sm:py-10">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-40 -left-40 size-80 rounded-full border border-(--color-brand-accent)/30 sm:-top-[330px] sm:-left-[330px] sm:size-[660px]"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-40 -bottom-40 size-80 rounded-full border border-(--color-brand-accent)/30 sm:-right-[315px] sm:-bottom-[315px] sm:size-[660px]"
      />
      <EyonLogo />
      {/* Keying the local form drops credentials whenever the demonstrated state changes. */}
      <LoginForm key={props.state} {...props} />
      <footer className="relative mt-8 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs leading-5 text-(--color-ink-muted) sm:mt-10 sm:text-sm">
        <span>© EYON</span>
        <span aria-hidden="true" className="h-4 border-l border-(--color-border)" />
        <button
          type="button"
          onClick={() => props.onNotice('Условия пока не опубликованы в этом предпросмотре.')}
          className={`rounded-sm hover:underline ${focus}`}
        >
          Условия
        </button>
        <span aria-hidden="true" className="h-4 border-l border-(--color-border)" />
        <button
          type="button"
          onClick={() =>
            props.onNotice(
              'Раздел конфиденциальности появится позднее. Форма предпросмотра не отправляет введённые данные.',
            )
          }
          className={`rounded-sm hover:underline ${focus}`}
        >
          Конфиденциальность
        </button>
      </footer>
    </main>
  );
}
