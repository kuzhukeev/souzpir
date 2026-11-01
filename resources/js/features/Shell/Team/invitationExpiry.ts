import { useEffect, useState } from 'react';

const dayMs = 24 * 60 * 60 * 1000;

type Anchor = { server: number; wall: number; monotonic: number };

/** Preserve the latest server anchor across Inertia history navigation. */
export function createServerClock(wallNow: () => number, monotonicNow: () => number) {
  let anchor: Anchor | null = null;
  let last: number | null = null;
  const read = () => {
    if (!anchor) return null;
    const next =
      anchor.server + Math.max(0, wallNow() - anchor.wall, monotonicNow() - anchor.monotonic);
    last = Math.max(last ?? next, next);
    return last;
  };
  return {
    observe(value: string) {
      const server = Date.parse(value);
      if (!Number.isFinite(server)) return null;
      const previous = read();
      anchor = {
        server: previous === null ? server : Math.max(previous, server),
        wall: wallNow(),
        monotonic: monotonicNow(),
      };
      last = anchor.server;
      return anchor.server;
    },
    read,
  };
}

const serverClock = createServerClock(
  () => Date.now(),
  () => performance.now(),
);

function currentSnapshot(asOf: string): number | null {
  const parsed = Date.parse(asOf);
  if (!Number.isFinite(parsed)) return null;
  return Math.max(parsed, serverClock.read() ?? parsed);
}

export function useInvitationTime(asOf: string, active: boolean): number | null {
  const [current, setCurrent] = useState(() => ({ asOf, value: currentSnapshot(asOf) }));
  useEffect(() => {
    if (!active) return;
    const initial = serverClock.observe(asOf);
    if (initial === null) return;
    const tick = () => setCurrent({ asOf, value: serverClock.read() });
    const startup = window.setTimeout(tick, 0);
    const timer = window.setInterval(tick, 1000);
    window.addEventListener('focus', tick);
    window.addEventListener('pageshow', tick);
    document.addEventListener('visibilitychange', tick);
    return () => {
      window.clearTimeout(startup);
      window.clearInterval(timer);
      window.removeEventListener('focus', tick);
      window.removeEventListener('pageshow', tick);
      document.removeEventListener('visibilitychange', tick);
    };
  }, [asOf, active]);
  if (!active) return null;
  const snapshot = currentSnapshot(asOf);
  if (snapshot === null) return null;
  return current.asOf === asOf && current.value !== null
    ? Math.max(current.value, snapshot)
    : snapshot;
}

export function invitationExpiry(expiresAt: string, now: number | null) {
  const expires = Date.parse(expiresAt);
  if (now === null || !Number.isFinite(now) || !Number.isFinite(expires)) {
    return { state: 'unknown' as const, label: 'Срок недоступен' };
  }
  const remaining = expires - now;
  if (remaining <= 0) return { state: 'expired' as const, label: 'Срок истёк' };
  if (remaining < dayMs) return { state: 'active' as const, label: 'Осталось менее суток' };
  const days = Math.floor(remaining / dayMs);
  const word = days % 10 === 1 && days % 100 !== 11 ? 'сутки' : 'суток';
  return { state: 'active' as const, label: `Осталось ${days} ${word}` };
}
