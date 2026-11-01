import { describe, expect, it } from 'vitest';
import { createServerClock, invitationExpiry } from './invitationExpiry';

describe('срок приглашения', () => {
  const now = Date.parse('2026-11-01T12:00:00.000Z');
  const day = 24 * 60 * 60 * 1000;
  const at = (offset: number) => new Date(now + offset).toISOString();

  it('отображает границы нуля и полных суток', () => {
    expect(invitationExpiry(at(-1), now).label).toBe('Срок истёк');
    expect(invitationExpiry(at(0), now).state).toBe('expired');
    expect(invitationExpiry(at(1), now).label).toBe('Осталось менее суток');
    expect(invitationExpiry(at(day - 1), now).label).toBe('Осталось менее суток');
    expect(invitationExpiry(at(day), now).label).toBe('Осталось 1 сутки');
    expect(invitationExpiry(at(day + 1), now).label).toBe('Осталось 1 сутки');
    expect(invitationExpiry(at(2 * day), now).label).toBe('Осталось 2 суток');
    expect(invitationExpiry(at(11 * day), now).label).toBe('Осталось 11 суток');
    expect(invitationExpiry(at(21 * day), now).label).toBe('Осталось 21 сутки');
  });

  it('не разрешает действие при неизвестном времени', () => {
    expect(invitationExpiry('invalid', now).state).toBe('unknown');
    expect(invitationExpiry(at(day), null).state).toBe('unknown');
    expect(invitationExpiry(at(day), Number.NaN).state).toBe('unknown');
  });

  it('ведёт отсчёт от сервера и не возвращается назад при старом состоянии истории', () => {
    let wall = 10_000;
    let monotonic = 50;
    const clock = createServerClock(
      () => wall,
      () => monotonic,
    );
    expect(clock.observe('invalid')).toBeNull();
    expect(clock.observe(new Date(now).toISOString())).toBe(now);
    wall -= 5000;
    monotonic += 3000;
    expect(clock.read()).toBe(now + 3000);
    expect(clock.observe(new Date(now - day).toISOString())).toBe(now + 3000);
    wall += 8000;
    expect(clock.read()).toBe(now + 11_000);
    wall -= 8000;
    expect(clock.read()).toBe(now + 11_000);
  });
});
