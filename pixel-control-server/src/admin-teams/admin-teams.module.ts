import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminTeamsController } from './admin-teams.controller';
import { AdminTeamsService } from './admin-teams.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminTeamsController],
  providers: [AdminTeamsService],
})
export class AdminTeamsModule {}
