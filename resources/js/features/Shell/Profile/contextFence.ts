import { requestKey } from './browser';

// Each in-flight change owns a separate marker. A failed or uncertain request
// deliberately leaves its marker behind: it may still commit at the server.
const prefix = 'souzpir.profile-context.pending.';
const listeners = new Set<() => void>();
let channel: BroadcastChannel | null = null;
try { if (typeof BroadcastChannel !== 'undefined') channel = new BroadcastChannel('souzpir.profile-context'); } catch { /* Storage events remain available. */ }

function broadcast() {
  try { channel?.postMessage('changed'); } catch { /* Storage events remain available. */ }
}

function changed() {
  document.querySelectorAll<HTMLElement>('[data-private-profile]').forEach((element) => { element.hidden = true; });
  listeners.forEach((listener) => listener());
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => { if (event.key === null || event.key.startsWith(prefix)) changed(); });
  channel?.addEventListener('message', changed);
}

export function subscribeProfileContext(listener: () => void) {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

export function profileContextIsStable(): boolean {
  try {
    // Verify that storage is usable, not just present. It is required to detect
    // changes started before this document loaded and in other tabs.
    const probe = 'souzpir.profile-context.probe';
    localStorage.setItem(probe, '1');
    localStorage.removeItem(probe);
    return !Object.keys(localStorage).some((key) => key.startsWith(prefix));
  } catch {
    return false;
  }
}

export function beginProfileContextChange(): string | null {
  try {
    const id = requestKey();
    localStorage.setItem(prefix + id, '1');
    changed();
    broadcast();
    return id;
  } catch { return null; }
}

export function finishProfileContextChange(id: string | null) {
  if (!id) return;
  try { localStorage.removeItem(prefix + id); } catch { /* Retain the closed state. */ }
  changed();
  broadcast();
}

// Only markers observed before the server rotates this session may be cleared.
export function pendingProfileContextChanges(): string[] {
  try { return Object.keys(localStorage).filter((key) => key.startsWith(prefix)); } catch { return []; }
}

export function finishProfileContextRecovery(keys: string[]) {
  try { keys.filter((key) => key.startsWith(prefix)).forEach((key) => localStorage.removeItem(key)); } catch { /* Keep the profile closed. */ }
  changed();
  broadcast();
}
