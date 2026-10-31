import type { Payer } from '@/types/Shell/Billing';
import { router } from '@inertiajs/react';

// Scoped process memory only. Never persist payer data in history or browser storage.
export type MemoryDraft = { scope: string; data: Payer; revision: string; errors: Record<string, string> };
let draft: MemoryDraft | null = null;
let listenersInstalled = false;
let activeForms = 0;
export const readPayerDraft = () => draft;
export function markPayerFormMounted() { activeForms += 1; return () => { activeForms -= 1; }; }
export function writePayerDraft(value: MemoryDraft) {
  if (!listenersInstalled && typeof window !== 'undefined') {
    listenersInstalled = true;
    window.addEventListener('pagehide', discardPayerDraft);
    window.addEventListener('beforeunload', (event) => { if (draft) { event.preventDefault(); event.returnValue = ''; } });
    router.on('before', (event) => {
      if (draft && activeForms === 0 && event.detail.visit.url.pathname === '/logout' && !window.confirm('Есть несохранённые изменения. Покинуть страницу?')) event.preventDefault();
    });
    router.on('start', (event) => { if (event.detail.visit.url.pathname === '/logout') discardPayerDraft(); });
  }
  draft = value;
}
export function discardPayerDraft() { draft = null; }
