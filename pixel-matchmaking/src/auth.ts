import NextAuth from "next-auth";
import { authConfig } from "@/features/auth/lib/auth.config";

/**
 * Single export point for Auth.js v5.
 * Used by: route handler, proxy, server components.
 */
export const { auth, handlers, signIn, signOut } = NextAuth(authConfig);
