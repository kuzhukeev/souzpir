export type LimitValue = { value: string | null; unit: string; configured: boolean };
export function formatLimit(limit: LimitValue): string {
  return limit.configured && limit.value !== null ? [limit.value, limit.unit].filter(Boolean).join(' ') : '—';
}
