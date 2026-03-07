import { describe, it, expect, vi, beforeEach } from "vitest";

// Mock the generated Prisma client to avoid actual DB connection
vi.mock("@/generated/prisma/client", () => {
  const MockPrismaClient = vi.fn();
  return { PrismaClient: MockPrismaClient };
});

describe("Prisma singleton client", () => {
  beforeEach(() => {
    // Clear module cache between tests to reset singleton
    vi.resetModules();
    // Clean up global singleton
    const g = globalThis as unknown as Record<string, unknown>;
    delete g.prisma;
  });

  it("exports a prisma instance", async () => {
    const { prisma } = await import("./prisma");
    expect(prisma).toBeDefined();
  });

  it("returns the same instance on multiple imports in dev mode", async () => {
    // First import creates the singleton
    const mod1 = await import("./prisma");
    const instance1 = mod1.prisma;

    // Manually set the global (simulating what the module does in dev)
    const g = globalThis as unknown as { prisma: unknown };
    g.prisma = instance1;

    // Second import should use the cached global
    vi.resetModules();
    const mod2 = await import("./prisma");
    expect(mod2.prisma).toBe(instance1);
  });
});
