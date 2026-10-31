/** Only an opaque server-issued key is retained between retries. */
const keyFor = (scope: string) => `support-submission:${scope}`;

export function pendingSubmission(scope: string): string | null {
  return sessionStorage.getItem(keyFor(scope));
}

export function submissionKey(scope: string, candidate: string): string {
  const storageKey = keyFor(scope);
  const key = sessionStorage.getItem(storageKey) ?? candidate;
  if (!key) throw new Error('submission key unavailable');
  sessionStorage.setItem(storageKey, key);
  if (sessionStorage.getItem(storageKey) !== key) throw new Error('session storage unavailable');
  return key;
}

export function clearSubmission(scope: string): void {
  sessionStorage.removeItem(keyFor(scope));
}
