import { expect, test } from '@playwright/test';
import { qrMatrix } from '../../../resources/js/features/Shell/Profile/qr';
import references from './qr-reference.json' with { type: 'json' };

test('local MFA QR matches independent ReportLab byte-mode reference matrices', () => {
  for (const reference of references) {
    const matrix = qrMatrix(reference.value);
    expect(matrix, `version ${reference.version}`).not.toBeNull();
    expect(matrix?.map((row) => row.map((black) => (black ? '1' : '0')).join(''))).toEqual(
      reference.matrix,
    );
  }
  expect(qrMatrix('x'.repeat(859))).toBeNull();
});
