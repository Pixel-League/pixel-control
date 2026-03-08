import { describe, it, expect, vi } from "vitest";

// Mock Prisma to avoid actual DB connection
vi.mock("@/shared/lib/prisma", () => ({
  prisma: {
    user: {
      upsert: vi.fn(),
      findUnique: vi.fn(),
    },
  },
}));

// Must import AFTER mocks are set up
const { authConfig } = await import("./auth.config");

describe("Auth.js configuration", () => {
  it('uses JWT session strategy', () => {
    expect(authConfig.session?.strategy).toBe("jwt");
  });

  it('has custom sign-in page pointing to /auth/signin', () => {
    expect(authConfig.pages?.signIn).toBe("/auth/signin");
  });

  it("includes exactly one provider", () => {
    expect(authConfig.providers).toHaveLength(1);
  });

  it('includes ManiaPlanet as the provider', () => {
    // Providers may be functions or objects — resolve if needed
    const provider = typeof authConfig.providers[0] === "function"
      ? (authConfig.providers[0] as () => { id: string })()
      : authConfig.providers[0];
    expect((provider as { id: string }).id).toBe("maniaplanet");
  });

  it("has signIn callback defined", () => {
    expect(authConfig.callbacks?.signIn).toBeDefined();
    expect(typeof authConfig.callbacks?.signIn).toBe("function");
  });

  it("has jwt callback defined", () => {
    expect(authConfig.callbacks?.jwt).toBeDefined();
    expect(typeof authConfig.callbacks?.jwt).toBe("function");
  });

  it("has session callback defined", () => {
    expect(authConfig.callbacks?.session).toBeDefined();
    expect(typeof authConfig.callbacks?.session).toBe("function");
  });
});
