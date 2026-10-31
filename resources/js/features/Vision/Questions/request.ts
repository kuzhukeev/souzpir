export async function request<T>(
  path: string,
  brandId: string,
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE' = 'GET',
  body?: unknown,
): Promise<T> {
  if (
    !/^\/vision\/questions\/(data|candidates(?:\/[A-Za-z0-9]+)?|approve|import-profile|copy-active)$/.test(
      path,
    ) ||
    !brandId
  )
    throw new Error('Не удалось определить активный бренд. Обновите страницу.');
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Brand-Id': brandId,
  };
  if (method !== 'GET') {
    const cookie = document.cookie.split('; ').find((x) => x.startsWith('XSRF-TOKEN='));
    if (!cookie) throw new Error('Сессия истекла. Войдите снова.');
    headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    headers['Content-Type'] = 'application/json';
  }
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    cache: 'no-store',
    redirect: 'error',
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const message =
      data && typeof data === 'object' && 'message' in data && typeof data.message === 'string'
        ? data.message
        : 'Не удалось выполнить действие.';
    throw new Error(message);
  }
  return data as T;
}
