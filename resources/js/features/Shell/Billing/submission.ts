import type { CreatedPlanRequest } from './request';

/** Persist only opaque identifiers and a receipt, never payer data. */
export type PendingPlanRequest = { plan_id: string; submission_key: string };
export type StoredPlanRequest = { state: 'pending'; request: PendingPlanRequest } | { state: 'confirmed'; request: PendingPlanRequest; receipt: CreatedPlanRequest };
const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/i;
const storageKey = (scope: string) => `billing-plan-request:${scope}`;
const validRequest = (value: unknown): value is PendingPlanRequest => {
  if (!value || typeof value !== 'object') return false;
  const data = value as Partial<PendingPlanRequest>;
  return typeof data.plan_id === 'string' && ulid.test(data.plan_id) && typeof data.submission_key === 'string' && ulid.test(data.submission_key);
};
const validReceipt = (value: unknown): value is CreatedPlanRequest => {
  if (!value || typeof value !== 'object') return false;
  const data = value as Partial<CreatedPlanRequest>;
  return typeof data.ticket_id === 'string' && ulid.test(data.ticket_id) && typeof data.created === 'boolean' && typeof data.next_submission_key === 'string' && ulid.test(data.next_submission_key) && ['not_configured', 'disabled', 'sent', 'uncertain'].includes(data.delivery_status ?? '');
};

export function storedPlanRequest(scope: string): StoredPlanRequest | null {
  if (!scope) throw new Error('scope unavailable');
  const value = sessionStorage.getItem(storageKey(scope));
  if (value === null) return null;
  const parsed: unknown = JSON.parse(value);
  if (!parsed || typeof parsed !== 'object') throw new Error('invalid request');
  const record = parsed as Partial<StoredPlanRequest>;
  if (record.state === 'pending' && validRequest(record.request)) return { state: 'pending', request: record.request };
  if (record.state === 'confirmed' && validRequest(record.request) && validReceipt(record.receipt) && record.receipt.next_submission_key.toUpperCase() !== record.request.submission_key.toUpperCase()) return { state: 'confirmed', request: record.request, receipt: record.receipt };
  throw new Error('invalid request');
}

function save(scope: string, record: StoredPlanRequest): void {
  sessionStorage.setItem(storageKey(scope), JSON.stringify(record));
  if (JSON.stringify(storedPlanRequest(scope)) !== JSON.stringify(record)) throw new Error('request storage unavailable');
}

export function rememberPlanRequest(scope: string, plan: string, candidate: string): PendingPlanRequest {
  const previous = storedPlanRequest(scope);
  if (previous) {
    if (previous.state !== 'pending' || previous.request.plan_id !== plan) throw new Error('another request exists');
    return previous.request;
  }
  const request = { plan_id: plan, submission_key: candidate };
  if (!validRequest(request)) throw new Error('invalid request identifiers');
  save(scope, { state: 'pending', request });
  return request;
}

export function confirmPlanRequest(scope: string, request: PendingPlanRequest, receipt: CreatedPlanRequest): void {
  const previous = storedPlanRequest(scope);
  if (previous?.state !== 'pending' || previous.request.plan_id !== request.plan_id || previous.request.submission_key !== request.submission_key || !validReceipt(receipt) || receipt.next_submission_key.toUpperCase() === request.submission_key.toUpperCase()) throw new Error('invalid receipt');
  save(scope, { state: 'confirmed', request, receipt });
}

export function clearPlanRequest(scope: string): void {
  sessionStorage.removeItem(storageKey(scope));
  if (sessionStorage.getItem(storageKey(scope)) !== null) throw new Error('request storage unavailable');
}
