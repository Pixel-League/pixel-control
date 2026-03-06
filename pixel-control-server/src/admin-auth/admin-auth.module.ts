import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminAuthController } from './admin-auth.controller';
import { AdminAuthService } from './admin-auth.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminAuthController],
  providers: [AdminAuthService],
})
export class AdminAuthModule {}
