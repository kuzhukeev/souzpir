import { afterEach, expect, it, vi } from 'vitest';
import { supportRequest, supportError, SupportRequestError } from './request';
import { supportCopy as c } from './copy';
afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});
it('sends the exact multipart files with same-origin CSRF and no manually assigned content type', async () => {
  document.cookie = 'XSRF-TOKEN=fixture%2Bcsrf; path=/';
  const file = new File(['fixture'], 'problem.txt', { type: 'text/plain' });
  const form = new FormData();
  form.set('body', 'Описание');
  form.set('support_access', '1');
  form.append('attachments[]', file);
  const fetcher = vi
    .fn()
    .mockResolvedValue(
      new Response(JSON.stringify({ data: { ticket: { id: 'ticket' } } }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  vi.stubGlobal('fetch', fetcher);
  const storage = vi.spyOn(Storage.prototype, 'setItem');
  const history = vi.spyOn(window.history, 'replaceState');
  expect(await supportRequest('/help/support', form)).toEqual({ ticket: { id: 'ticket' } });
  expect(fetcher).toHaveBeenCalledWith(
    '/help/support',
    expect.objectContaining({
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      body: form,
      headers: expect.objectContaining({
        'X-XSRF-TOKEN': 'fixture+csrf',
        Accept: 'application/json',
      }),
    }),
  );
  const init = fetcher.mock.calls[0][1];
  expect(init.headers['Content-Type']).toBeUndefined();
  expect(init.body.get('attachments[]')).toBe(file);
  expect(storage).not.toHaveBeenCalled();
  expect(history).not.toHaveBeenCalled();
});
it('preserves server validation errors without discarding attachment field indexes', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({
            message: 'Проверьте вложения.',
            errors: { 'attachments.0': ['Неверный формат.'] },
          }),
          { status: 422 },
        ),
      ),
  );
  try {
    await supportRequest('/help/support');
    throw Error('Expected validation failure');
  } catch (error) {
    expect(error).toBeInstanceOf(SupportRequestError);
    expect(supportError(error)).toEqual({
      message: 'Проверьте вложения.',
      fields: { 'attachments.0': 'Неверный формат.' },
    });
  }
});
it.each([
  [403, c.forbidden],
  [419, c.sessionExpired],
  [500, c.requestFailed],
])('uses a safe user-facing error for HTTP %s', async (status, message) => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ message: 'Internal exception details' }), { status }),
      ),
  );
  await expect(supportRequest('/help/support')).rejects.toThrow(message);
});
it('does not suggest blindly repeating an uncertain POST after transport failure', async () => {
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Transport failure')));
  let caught: unknown;
  try {
    await supportRequest('/help/support');
  } catch (error) {
    caught = error;
  }
  expect(supportError(caught).message).toBe(c.requestFailed);
  expect(c.requestFailed).toContain('перед повтором');
});
it('rejects a successful non-JSON login response instead of claiming a created ticket', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(new Response('<html>Login</html>', { status: 200 })),
  );
  await expect(supportRequest('/help/support')).rejects.toThrow(c.requestFailed);
});
