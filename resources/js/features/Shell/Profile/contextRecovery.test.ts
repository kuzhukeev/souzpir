import { afterEach, expect, it } from 'vitest';
import { beginProfileContextChange, finishProfileContextChange, finishProfileContextRecovery, pendingProfileContextChanges, profileContextIsStable } from './contextFence';

afterEach(() => localStorage.clear());

it('clears only markers observed before a confirmed recovery', () => {
  const first = beginProfileContextChange();
  const captured = pendingProfileContextChanges();
  const second = beginProfileContextChange();
  finishProfileContextRecovery(captured);
  expect(profileContextIsStable()).toBe(false);
  finishProfileContextChange(second);
  expect(profileContextIsStable()).toBe(true);
  expect(first).toBeTruthy();
});
