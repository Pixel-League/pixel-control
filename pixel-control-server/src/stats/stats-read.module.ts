import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { StatsReadController } from './stats-read.controller';
import { StatsReadService } from './stats-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [StatsReadController],
  providers: [StatsReadService],
})
export class StatsReadModule {}
