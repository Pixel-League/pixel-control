import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminMapsController } from './admin-maps.controller';
import { AdminMapsService } from './admin-maps.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminMapsController],
  providers: [AdminMapsService],
})
export class AdminMapsModule {}
