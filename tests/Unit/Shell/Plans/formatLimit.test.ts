import { describe, expect, it } from 'vitest';
import { formatLimit } from '../../../../resources/js/features/Shell/Plans/formatLimit';
describe('formatLimit', () => {
  it('shows an em dash for unset values and preserves a configured zero', () => {
    expect(formatLimit({ value: null, unit: 'шт.', configured: false })).toBe('—');
    expect(formatLimit({ value: '0', unit: 'шт.', configured: true })).toBe('0 шт.');
  });
});
