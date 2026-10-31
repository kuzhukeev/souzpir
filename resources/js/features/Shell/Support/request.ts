import { supportCopy as c } from './copy';
export class SupportRequestError extends Error {
  constructor(
    message: string,
    public fields: Record<string, string> = {},
  ) {
    super(message);
  }
}
export function supportError(error: unknown): { message: string; fields: Record<string, string> } {
  return error instanceof SupportRequestError
    ? { message: error.message, fields: error.fields }
    : { message: c.requestFailed, fields: {} };
}
export async function supportRequest<T>(url: string, body?: FormData): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
  if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token.slice(11));
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    cache: 'no-store',
    headers,
    body,
  });
  const parsed = (await response.json().catch(() => null)) as {
    data?: T;
    message?: string;
    errors?: Record<string, string | string[]>;
  } | null;
  if (!response.ok) {
    const fields =
      response.status === 422
        ? Object.fromEntries(
            Object.entries(parsed?.errors ?? {}).map(([key, value]) => [
              key,
              Array.isArray(value) ? value[0] : value,
            ]),
          )
        : {};
    throw new SupportRequestError(
      response.status === 422
        ? parsed?.message || c.invalid
        : response.status === 403
          ? c.forbidden
          : response.status === 419
            ? c.sessionExpired
            : c.requestFailed,
      fields,
    );
  }
  if (!parsed || !('data' in parsed)) throw new SupportRequestError(c.requestFailed);
  return parsed.data as T;
}
