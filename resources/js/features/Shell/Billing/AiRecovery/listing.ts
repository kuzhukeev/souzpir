const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

export type RecoveryCall = {
  id: string;
  status: 'reserved' | 'uncertain';
  family: 'seller' | 'vision' | 'other';
  createdAt: string | null;
};
export type RecoveryPage = { calls: RecoveryCall[]; nextCursor: string | null };

/** Keep only the fields required by the owner view, never provider data or amounts. */
export function parseRecoveryPage(value: unknown): RecoveryPage {
  if (!value || typeof value !== 'object') throw new Error('Invalid response');
  const payload = value as Record<string, unknown>;
  if (!Array.isArray(payload.calls) || payload.calls.length > 50)
    throw new Error('Invalid response');
  const nextCursor = payload.nextCursor;
  if (nextCursor !== null && (typeof nextCursor !== 'string' || !ulid.test(nextCursor))) {
    throw new Error('Invalid response');
  }
  const calls = payload.calls.map((value): RecoveryCall => {
    if (!value || typeof value !== 'object') throw new Error('Invalid response');
    const row = value as Record<string, unknown>;
    if (
      typeof row.id !== 'string' ||
      !ulid.test(row.id) ||
      (row.status !== 'reserved' && row.status !== 'uncertain') ||
      typeof row.family !== 'string' ||
      (row.createdAt !== null && typeof row.createdAt !== 'string')
    ) {
      throw new Error('Invalid response');
    }
    return {
      id: row.id,
      status: row.status,
      family: row.family === 'seller' || row.family === 'vision' ? row.family : 'other',
      createdAt: row.createdAt,
    };
  });
  return { calls, nextCursor };
}
