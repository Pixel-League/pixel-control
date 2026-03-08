import { describe, it, expect, beforeEach } from 'vitest';
import { configure } from 'mobx';
import { authStore } from './authStore';
import type { Session } from 'next-auth';

configure({ enforceActions: 'never' });

beforeEach(() => {
  authStore.user = null;
  authStore.isLoading = true;
});

describe('authStore', () => {
  it('has correct initial state', () => {
    expect(authStore.user).toBeNull();
    expect(authStore.isLoading).toBe(true);
  });

  it('setSession updates user and isLoading', () => {
    const mockSession: Session = {
      user: {
        login: 'testlogin',
        nickname: 'TestPlayer',
        path: null,
        role: 'player',
        email: 'test@example.com',
      },
      expires: '2099-01-01T00:00:00.000Z',
    };

    authStore.setSession(mockSession, false);

    expect(authStore.user?.login).toBe('testlogin');
    expect(authStore.user?.nickname).toBe('TestPlayer');
    expect(authStore.isLoading).toBe(false);
  });

  it('setSession with null session clears user', () => {
    authStore.setSession(null, false);
    expect(authStore.user).toBeNull();
    expect(authStore.isLoading).toBe(false);
  });

  it('setSession with loading=true marks loading state', () => {
    authStore.setSession(null, true);
    expect(authStore.isLoading).toBe(true);
  });
});
