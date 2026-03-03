import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { CommonModule } from '../common/common.module';
import { AdminProxyService } from './admin-proxy.service';
import { ManiaControlSocketClient } from './maniacontrol-socket.client';

/**
 * Shared module providing ManiaControl socket communication infrastructure
 * for all P3 admin command modules.
 *
 * Exported: AdminProxyService (high-level action proxy with auth injection)
 */
@Module({
  imports: [CommonModule, ConfigModule],
  providers: [ManiaControlSocketClient, AdminProxyService],
  exports: [AdminProxyService],
})
export class AdminProxyModule {}
