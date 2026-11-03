import { describe, expect, it } from 'vitest';
import { subjectPath } from './subjectPath';
describe('subjectPath', () => {
  const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
  it('связывает только известные типы объектов с их страницами', () => {
    expect(subjectPath('conversation', id)).toBe(`/seller/dialogs/${id}`);
    expect(subjectPath('contact', id)).toBe(`/seller/customers/${id}`);
    expect(subjectPath('unknown', id)).toBeNull();
    expect(subjectPath('contact', '../outside')).toBeNull();
  });
});
