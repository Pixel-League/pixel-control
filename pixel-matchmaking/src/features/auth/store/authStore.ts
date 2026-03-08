'use client';

import { makeAutoObservable } from 'mobx';
import type { Session } from 'next-auth';

type SessionUser = Session['user'] | null;

class AuthStore {
  user: SessionUser = null;
  isLoading = true;

  constructor() {
    makeAutoObservable(this);
  }

  setSession(session: Session | null, loading: boolean) {
    this.user = session?.user ?? null;
    this.isLoading = loading;
  }
}

export const authStore = new AuthStore();
