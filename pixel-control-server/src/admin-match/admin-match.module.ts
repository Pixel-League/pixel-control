import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminMatchController } from './admin-match.controller';
import { AdminMatchService } from './admin-match.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminMatchController],
  providers: [AdminMatchService],
})
export class AdminMatchModule {}
