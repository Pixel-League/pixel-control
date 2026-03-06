import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { CommonModule } from '../common/common.module';
import { VetoDraftProxyService } from './veto-draft-proxy.service';

/**
 * Shared module providing VetoDraft socket communication infrastructure.
 *
 * Reuses ManiaControlSocketClient from AdminProxyModule but sends
 * PixelControl.VetoDraft.* method names instead of ExecuteAction.
 *
 * Exported: VetoDraftProxyService
 */
@Module({
  imports: [CommonModule, ConfigModule, AdminProxyModule],
  providers: [VetoDraftProxyService],
  exports: [VetoDraftProxyService],
})
export class VetoDraftProxyModule {}
