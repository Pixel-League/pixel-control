import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminWhitelistController } from './admin-whitelist.controller';
import { AdminWhitelistService } from './admin-whitelist.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminWhitelistController],
  providers: [AdminWhitelistService],
})
export class AdminWhitelistModule {}
