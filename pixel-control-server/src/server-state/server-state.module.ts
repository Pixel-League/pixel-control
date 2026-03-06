import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { ServerStateController } from './server-state.controller';
import { ServerStateService } from './server-state.service';

@Module({
  imports: [CommonModule],
  controllers: [ServerStateController],
  providers: [ServerStateService],
})
export class ServerStateModule {}
