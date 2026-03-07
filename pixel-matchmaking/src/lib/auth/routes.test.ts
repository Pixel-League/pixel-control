import { describe, it, expect } from "vitest";
import {
  isProtectedRoute,
  isPublicRoute,
  PROTECTED_PREFIXES,
  PUBLIC_ROUTES,
} from "./routes";

describe("PROTECTED_PREFIXES", () => {
  it("contains /play, /me, /admin", () => {
    expect(PROTECTED_PREFIXES).toContain("/play");
    expect(PROTECTED_PREFIXES).toContain("/me");
    expect(PROTECTED_PREFIXES).toContain("/admin");
  });
});

describe("PUBLIC_ROUTES", () => {
  it("contains the home route", () => {
    expect(PUBLIC_ROUTES).toContain("/");
  });

  it("contains /leaderboard", () => {
    expect(PUBLIC_ROUTES).toContain("/leaderboard");
  });

  it("contains /matches", () => {
    expect(PUBLIC_ROUTES).toContain("/matches");
  });

  it("contains /auth", () => {
    expect(PUBLIC_ROUTES).toContain("/auth");
  });

  it("contains /api/auth", () => {
    expect(PUBLIC_ROUTES).toContain("/api/auth");
  });
});

describe("isProtectedRoute", () => {
  it("returns true for /play", () => {
    expect(isProtectedRoute("/play")).toBe(true);
  });

  it("returns true for /play/queue", () => {
    expect(isProtectedRoute("/play/queue")).toBe(true);
  });

  it("returns true for /me", () => {
    expect(isProtectedRoute("/me")).toBe(true);
  });

  it("returns true for /me/settings", () => {
    expect(isProtectedRoute("/me/settings")).toBe(true);
  });

  it("returns true for /admin", () => {
    expect(isProtectedRoute("/admin")).toBe(true);
  });

  it("returns true for /admin/users", () => {
    expect(isProtectedRoute("/admin/users")).toBe(true);
  });

  it("returns false for /", () => {
    expect(isProtectedRoute("/")).toBe(false);
  });

  it("returns false for /leaderboard", () => {
    expect(isProtectedRoute("/leaderboard")).toBe(false);
  });

  it("returns false for /matches", () => {
    expect(isProtectedRoute("/matches")).toBe(false);
  });

  it("returns false for /auth/signin", () => {
    expect(isProtectedRoute("/auth/signin")).toBe(false);
  });

  it("returns false for /api/auth/callback", () => {
    expect(isProtectedRoute("/api/auth/callback")).toBe(false);
  });

  it("returns false for /player/somelogin", () => {
    expect(isProtectedRoute("/player/somelogin")).toBe(false);
  });
});

describe("isPublicRoute", () => {
  it("returns true for /", () => {
    expect(isPublicRoute("/")).toBe(true);
  });

  it("returns true for /leaderboard", () => {
    expect(isPublicRoute("/leaderboard")).toBe(true);
  });

  it("returns true for /matches", () => {
    expect(isPublicRoute("/matches")).toBe(true);
  });

  it("returns true for /auth/signin", () => {
    expect(isPublicRoute("/auth/signin")).toBe(true);
  });

  it("returns true for /api/auth/callback/maniaplanet", () => {
    expect(isPublicRoute("/api/auth/callback/maniaplanet")).toBe(true);
  });

  it("returns false for /play", () => {
    expect(isPublicRoute("/play")).toBe(false);
  });

  it("returns false for /me", () => {
    expect(isPublicRoute("/me")).toBe(false);
  });

  it("returns false for /admin", () => {
    expect(isPublicRoute("/admin")).toBe(false);
  });

  it("returns false for unknown paths like /about", () => {
    expect(isPublicRoute("/about")).toBe(false);
  });
});
