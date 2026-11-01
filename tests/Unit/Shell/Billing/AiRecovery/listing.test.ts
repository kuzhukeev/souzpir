import { expect, it } from 'vitest';
import { parseRecoveryPage } from '@/features/Shell/Billing/AiRecovery/listing';

const id = '01J00000000000000000000001';
const call = {
  id,
  status: 'uncertain',
  family: 'seller',
  createdAt: '2026-11-01T00:00:00Z',
  reservedMicros: '123456',
  provider: 'PRIVATE_PROVIDER',
  secret: 'PRIVATE_SECRET',
};

it('выбирает только отображаемые поля и не переносит суммы или секреты', () => {
  const page = parseRecoveryPage({ calls: [call], nextCursor: id });
  expect(page).toEqual({
    calls: [{ id, status: 'uncertain', family: 'seller', createdAt: call.createdAt }],
    nextCursor: id,
  });
  expect(JSON.stringify(page)).not.toMatch(/123456|PRIVATE/);
  expect(
    parseRecoveryPage({ calls: [{ ...call, family: 'unexpected' }], nextCursor: null }).calls[0]
      .family,
  ).toBe('other');
});

it('отклоняет некорректные ответы и курсоры без отображения входных данных', () => {
  for (const data of [
    null,
    {},
    { calls: [], nextCursor: 'wrong' },
    { calls: [call], nextCursor: undefined },
    { calls: [{ ...call, id: 'bad' }], nextCursor: null },
    { calls: [{ ...call, status: 'completed' }], nextCursor: null },
    { calls: Array.from({ length: 51 }, () => call), nextCursor: null },
  ]) {
    expect(() => parseRecoveryPage(data)).toThrow('Invalid response');
  }
  expect(parseRecoveryPage({ calls: [], nextCursor: null })).toEqual({
    calls: [],
    nextCursor: null,
  });
});
