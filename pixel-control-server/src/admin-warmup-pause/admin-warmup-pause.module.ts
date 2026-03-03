import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminWarmupPauseController } from './admin-warmup-pause.controller';
import { AdminWarmupPauseService } from './admin-warmup-pause.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminWarmupPauseController],
  providers: [AdminWarmupPauseService],
})
export class AdminWarmupPauseModule {}
