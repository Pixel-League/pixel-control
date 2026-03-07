/* eslint-disable @typescript-eslint/no-unused-vars */
import type { DefaultSession } from "next-auth";

/**
 * Module augmentation for Auth.js v5 — extend Session, User, and JWT
 * with ManiaPlanet-specific fields.
 *
 * @see https://authjs.dev/getting-started/typescript#module-augmentation
 */

declare module "next-auth" {
  interface User {
    login?: string;
    nickname?: string;
    path?: string | null;
    role?: string;
  }

  interface Session extends DefaultSession {
    user: DefaultSession["user"] & {
      login: string;
      nickname: string;
      path: string | null;
      role: string;
    };
  }
}

declare module "next-auth/jwt" {
  interface JWT {
    login?: string;
    nickname?: string;
    path?: string | null;
    role?: string;
  }
}
