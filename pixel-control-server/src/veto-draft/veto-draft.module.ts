import { Module } from '@nestjs/common';

import { VetoDraftProxyModule } from '../veto-draft-proxy/veto-draft-proxy.module';
import { VetoDraftController } from './veto-draft.controller';
import { VetoDraftService } from './veto-draft.service';

@Module({
  imports: [VetoDraftProxyModule],
  controllers: [VetoDraftController],
  providers: [VetoDraftService],
})
export class VetoDraftModule {}
