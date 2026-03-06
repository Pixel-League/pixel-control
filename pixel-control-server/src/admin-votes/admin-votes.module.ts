import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminVotesController } from './admin-votes.controller';
import { AdminVotesService } from './admin-votes.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminVotesController],
  providers: [AdminVotesService],
})
export class AdminVotesModule {}
