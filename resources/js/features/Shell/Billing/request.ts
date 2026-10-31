import type { PendingPlanRequest } from './submission';

export type DeliveryStatus = 'not_configured' | 'disabled' | 'sent' | 'uncertain';
export type CreatedPlanRequest = { ticket_id: string; created: boolean; delivery_status: DeliveryStatus; next_submission_key: string };
export const deliveryMessages: Record<DeliveryStatus, string> = {
  not_configured: 'Уведомление не настроено. Сотрудник поддержки может не узнать о заявке.',
  disabled: 'Отправка уведомлений отключена. Сотрудник поддержки может не узнать о заявке.',
  sent: 'Уведомление отправлено.',
  uncertain: 'Статус отправки уведомления неизвестен. Сотрудник поддержки может не узнать о заявке.',
};

export async function submitPlanRequest(body: PendingPlanRequest, scope: string): Promise<CreatedPlanRequest> {
  if (!scope) throw new Error('Не удалось подтвердить контекст. Обновите страницу.');
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Billing-Context': scope };
  const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
  if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token.slice(11));
  let response: Response;
  try {
    response = await fetch('/billing/plans/request', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers, body: JSON.stringify(body) });
  } catch {
    throw new Error('Не удалось подтвердить создание заявки. Повторите ту же попытку.');
  }
  if (!response.ok) {
    if (response.status === 409) throw new Error('Контекст изменился или попытка конфликтует с другой заявкой. Вернитесь к исходному бренду или обратитесь в поддержку.');
    if (response.status === 403) throw new Error('Недостаточно прав для создания заявки.');
    if (response.status === 404) throw new Error('Не удалось подтвердить заявку. Проверьте доступ и повторите ту же попытку.');
    if (response.status === 419) throw new Error('Сессия истекла. Войдите снова и повторите заявку.');
    if (response.status === 422) throw new Error('Не удалось проверить выбранный тариф. Повторите запрос или обратитесь в поддержку.');
    throw new Error('Не удалось подтвердить создание заявки. Повторите ту же попытку.');
  }
  const json: unknown = await response.json().catch(() => null);
  if (!json || typeof json !== 'object' || !('data' in json)) throw new Error('Не удалось подтвердить создание заявки. Повторите ту же попытку.');
  const data = (json as { data: Partial<CreatedPlanRequest> }).data;
  if (!data || typeof data.ticket_id !== 'string' || !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(data.ticket_id) || typeof data.created !== 'boolean' || typeof data.next_submission_key !== 'string' || !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(data.next_submission_key) || !data.delivery_status || !Object.prototype.hasOwnProperty.call(deliveryMessages, data.delivery_status)) throw new Error('Не удалось подтвердить создание заявки. Повторите ту же попытку.');
  return { ticket_id: data.ticket_id, created: data.created, delivery_status: data.delivery_status, next_submission_key: data.next_submission_key };
}
