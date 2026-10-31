import { afterEach, describe, expect, it, vi } from 'vitest';
import { beginProfileContextChange, finishProfileContextChange, profileContextIsStable, subscribeProfileContext } from './contextFence';

afterEach(() => { localStorage.clear(); document.body.innerHTML = ''; });

describe('profile context changes', () => {
  it('hides a visible profile immediately and keeps independent switches fenced until each succeeds', () => {
    const privateElement = document.createElement('section');
    privateElement.dataset.privateProfile = '';
    document.body.append(privateElement);
    const listener = vi.fn();
    const unsubscribe = subscribeProfileContext(listener);

    const first = beginProfileContextChange();
    expect(first).toBeTruthy();
    expect(privateElement.hidden).toBe(true);
    expect(listener).toHaveBeenCalled();
    expect(profileContextIsStable()).toBe(false);
    const second = beginProfileContextChange();
    finishProfileContextChange(first);
    expect(profileContextIsStable()).toBe(false);
    finishProfileContextChange(second);
    expect(profileContextIsStable()).toBe(true);
    unsubscribe();
  });

  it('hides a profile on another tab storage event', () => {
    const privateElement = document.createElement('section');
    privateElement.dataset.privateProfile = '';
    document.body.append(privateElement);
    window.dispatchEvent(new StorageEvent('storage', { key: 'souzpir.profile-context.pending.test', newValue: '1' }));
    expect(privateElement.hidden).toBe(true);
  });

  it('leaves the profile closed when storage cannot be checked', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('unavailable'); });
    expect(profileContextIsStable()).toBe(false);
  });

});
