export async function mutate(
  path: string,
  method: 'POST' | 'PATCH',
  brandId: string,
  data: Record<string, unknown>,
): Promise<string> {
  if (!/^\/vision\/(quiz\/(step\/[1-7]|submit)|profile\/step\/[1-7])$/.test(path) || !brandId)
    throw new Error('Не удалось определить активный бренд. Обновите страницу.');
  const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
  if (!cookie) throw new Error('Сессия истекла. Войдите снова.');
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    redirect: 'error',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
      'X-Brand-Id': brandId,
    },
    body: JSON.stringify(data),
  });
  const result: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const message =
      result &&
      typeof result === 'object' &&
      'message' in result &&
      typeof result.message === 'string'
        ? result.message
        : 'Не удалось сохранить данные. Попробуйте ещё раз.';
    throw new Error(message);
  }
  if (
    !result ||
    typeof result !== 'object' ||
    !('ok' in result) ||
    result.ok !== true ||
    !('redirect' in result) ||
    typeof result.redirect !== 'string' ||
    !/^\/vision\/(quiz\/[1-8]|profile)$/.test(result.redirect)
  )
    throw new Error('Сервер вернул неожиданный ответ. Обновите страницу.');
  return result.redirect;
}
