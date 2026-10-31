/** Same-origin only: mutations require the active session and Laravel CSRF cookie. */
export async function plannedRequest(url: string, method: 'POST' | 'PUT', body: { integration?: string; enabled?: boolean }, brandId: string): Promise<void> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Brand-Id': brandId,
  };
  const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
  if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token.slice(11));
  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error('Не удалось связаться с сервером. Проверьте состояние после обновления страницы.');
  }
  if (!response.ok) {
    if (response.status === 403) throw new Error('Недостаточно прав для изменения настроек.');
    if (response.status === 404) throw new Error('Бренд или доступ изменились. Обновите страницу.');
    if (response.status === 419) throw new Error('Сессия истекла. Обновите страницу и повторите.');
    if (response.status === 422) throw new Error('Настройку пока нельзя сохранить.');
    throw new Error('Не удалось сохранить. Попробуйте ещё раз.');
  }
  const parsed = await response.json().catch(() => null) as { data?: { integration?: unknown; interested?: unknown; enabled?: unknown } } | null;
  if (!parsed?.data || (method === 'POST' && (parsed.data.integration !== body.integration || parsed.data.interested !== true))
      || (method === 'PUT' && parsed.data.enabled !== body.enabled)) {
    throw new Error('Не удалось подтвердить сохранение. Обновите страницу и проверьте состояние.');
  }
}
