import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { PlayersReadController } from './players-read.controller';
import { PlayersReadService } from './players-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [PlayersReadController],
  providers: [PlayersReadService],
})
export class PlayersReadModule {}
