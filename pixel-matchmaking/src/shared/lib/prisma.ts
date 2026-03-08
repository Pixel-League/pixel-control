import { PrismaClient } from '@/generated/prisma/client';

/**
 * Singleton Prisma client — avoids creating multiple database connections
 * during Next.js hot-reload in development.
 */

const globalForPrisma = globalThis as unknown as {
  prisma: PrismaClient | undefined;
};

export const prisma = globalForPrisma.prisma ?? new PrismaClient();

if (process.env.NODE_ENV !== 'production') {
  globalForPrisma.prisma = prisma;
}
