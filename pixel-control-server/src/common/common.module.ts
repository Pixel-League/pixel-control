import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { PrismaModule } from '../prisma/prisma.module';
import { ServerResolverService } from './services/server-resolver.service';

/**
 * CommonModule provides shared infrastructure for read modules:
 * - ServerResolverService: resolves serverLogin to Server record or throws 404.
 * All read modules (PlayersReadModule, StatsReadModule, etc.) import CommonModule
 * instead of directly importing PrismaModule + ConfigModule for server resolution.
 */
@Module({
  imports: [PrismaModule, ConfigModule],
  providers: [ServerResolverService],
  exports: [ServerResolverService],
})
export class CommonModule {}
