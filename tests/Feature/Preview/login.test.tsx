import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LoginPreview, { type LoginState } from '../../../resources/js/features/Preview/LoginPreview';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function setup(state: LoginState = 'default') {
  const onNotice = vi.fn();
  const onStateChange = vi.fn();
  const props = { state, onNotice, onStateChange };
  return { ...render(<LoginPreview {...props} />), ...props, user: userEvent.setup() };
}

describe('local login preview', () => {
  it('labels fields and validates locally before any success notice', async () => {
    const { user, onNotice } = setup();
    await user.click(screen.getByRole('button', { name: 'Войти' }));
    expect(screen.getByLabelText('Электронная почта')).toHaveFocus();
    expect(screen.getByText('Введите корректный адрес электронной почты.')).toBeInTheDocument();
    expect(screen.getByText('Введите пароль.')).toBeInTheDocument();
    expect(screen.getByLabelText('Пароль')).toHaveAttribute('aria-invalid', 'true');
    expect(onNotice).not.toHaveBeenCalled();
    await user.type(screen.getByLabelText('Электронная почта'), 'invalid-address');
    await user.type(screen.getByLabelText('Пароль'), 'local-demo-only');
    await user.click(screen.getByRole('button', { name: 'Войти' }));
    expect(screen.getByText('Введите корректный адрес электронной почты.')).toBeInTheDocument();
    expect(onNotice).not.toHaveBeenCalled();
  });

  it('toggles password visibility with an accessible pressed state', async () => {
    const { user } = setup();
    const password = screen.getByLabelText('Пароль');
    await user.type(password, 'local-demo-only');
    expect(password).toHaveAttribute('type', 'password');
    await user.click(screen.getByRole('button', { name: 'Показать пароль' }));
    expect(password).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Скрыть пароль' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await user.click(screen.getByRole('button', { name: 'Скрыть пароль' }));
    expect(password).toHaveAttribute('type', 'password');
  });

  it('keeps remember local and never sends or persists credentials', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    const { user, onNotice } = setup();
    await user.type(screen.getByLabelText('Электронная почта'), 'demo@example.test');
    await user.type(screen.getByLabelText('Пароль'), 'local-demo-only');
    await user.click(screen.getByRole('checkbox', { name: 'Запомнить меня' }));
    expect(screen.getByRole('checkbox')).toBeChecked();
    await user.click(screen.getByRole('button', { name: 'Войти' }));
    expect(onNotice).toHaveBeenCalledWith(expect.stringContaining('Вход не выполняется'));
    expect(screen.getByLabelText('Пароль')).toHaveValue('');
    expect(storage).not.toHaveBeenCalled();
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Пароль')).toHaveAttribute('autocomplete', 'off');
  });

  it('drops the password and remember state when the demonstrated state changes', async () => {
    const { user, rerender, onNotice, onStateChange } = setup();
    await user.type(screen.getByLabelText('Пароль'), 'local-demo-only');
    await user.click(screen.getByRole('checkbox'));
    rerender(<LoginPreview state="invalid" onNotice={onNotice} onStateChange={onStateChange} />);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Неверные данные');
    expect(screen.getByRole('alert')).toHaveTextContent('Неверная почта или пароль');
    expect(screen.getByLabelText('Пароль')).toHaveValue('');
    expect(screen.getByRole('checkbox')).not.toBeChecked();
  });

  it('shows a disabled action and the original lockout message', () => {
    const { onNotice } = setup('locked');
    expect(screen.getByRole('button', { name: 'Войти' })).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('Попробуйте через 10 минут');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('После 5 попыток');
    expect(onNotice).not.toHaveBeenCalled();
  });

  it('disables input during the loading demonstration', () => {
    setup('loading');
    expect(screen.getByRole('button', { name: 'Загрузка…' })).toBeDisabled();
    expect(screen.getByLabelText('Электронная почта')).toBeDisabled();
    expect(screen.getByLabelText('Пароль')).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent('Демонстрация состояния загрузки');
  });

  it('validates six MFA digits without pretending to authenticate', async () => {
    const { user, onNotice, onStateChange } = setup('mfa');
    const code = screen.getByLabelText('Код подтверждения');
    fireEvent.change(code, { target: { value: '123' } });
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Введите 6 цифр кода.');
    expect(code).toHaveFocus();
    fireEvent.change(code, { target: { value: '12a3456' } });
    expect(code).toHaveValue('123456');
    await user.click(screen.getByRole('button', { name: 'Подтвердить' }));
    expect(onNotice).toHaveBeenCalledWith(expect.stringContaining('не проверяется'));
    expect(code).toHaveValue('');
    await user.click(screen.getByRole('button', { name: 'Назад ко входу' }));
    expect(onStateChange).toHaveBeenCalledWith('default');
  });

  it('explains unavailable actions without navigating away', async () => {
    const { user, onNotice } = setup();
    for (const name of ['Забыли пароль?', 'Зарегистрироваться', 'Условия', 'Конфиденциальность']) {
      await user.click(screen.getByRole('button', { name }));
    }
    expect(onNotice).toHaveBeenCalledTimes(4);
    expect(screen.getByRole('heading', { name: 'Вход в кабинет' })).toBeInTheDocument();
  });
});
