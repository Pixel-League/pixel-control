import type { NextAuthConfig } from "next-auth";
import ManiaPlanet from "@/features/auth/lib/maniaplanet-provider";
import { prisma } from "@/shared/lib/prisma";

/**
 * Auth.js v5 configuration for Pixel MatchMaking.
 *
 * - Provider: ManiaPlanet custom OAuth2.
 * - Session strategy: JWT (user also persisted in DB for admin role checks).
 * - Custom sign-in page at /auth/signin.
 */
export const authConfig: NextAuthConfig = {
  providers: [
    ManiaPlanet({
      clientId: process.env.AUTH_MANIAPLANET_ID,
      clientSecret: process.env.AUTH_MANIAPLANET_SECRET,
    }),
  ],
  session: {
    strategy: "jwt",
  },
  pages: {
    signIn: "/auth/signin",
  },
  callbacks: {
    /**
     * Called after a successful OAuth login.
     * Upsert the user in the database (create on first login, update nickname/path on subsequent logins).
     */
    async signIn({ user, profile }) {
      if (!profile) return true;

      const mpProfile = profile as { login?: string; nickname?: string; path?: string };
      const login = mpProfile.login ?? user.id;
      const nickname = mpProfile.nickname ?? user.name ?? login ?? "Unknown";
      const path = mpProfile.path ?? null;

      if (!login) return true;

      await prisma.user.upsert({
        where: { login },
        create: {
          login,
          nickname,
          path,
          role: "player",
        },
        update: {
          nickname,
          path,
        },
      });

      return true;
    },

    /**
     * Enrich the JWT token with ManiaPlanet-specific fields from the DB user.
     */
    async jwt({ token, user, profile }) {
      // On initial sign-in, `user` and `profile` are available
      if (profile) {
        const mpProfile = profile as { login?: string; nickname?: string; path?: string };
        const login = mpProfile.login ?? user?.id;

        if (login) {
          const dbUser = await prisma.user.findUnique({ where: { login } });
          if (dbUser) {
            token.login = dbUser.login;
            token.nickname = dbUser.nickname;
            token.path = dbUser.path;
            token.role = dbUser.role;
          }
        }
      }

      return token;
    },

    /**
     * Expose custom fields on the client-side session object.
     */
    session({ session, token }) {
      if (token.login) {
        session.user.login = token.login as string;
        session.user.nickname = token.nickname as string;
        session.user.path = (token.path as string | undefined) ?? null;
        session.user.role = token.role as string;
      }
      return session;
    },
  },
};
