import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { EliteStatsReadController } from './elite-stats-read.controller';
import { EliteStatsReadService } from './elite-stats-read.service';
import { StatsReadController } from './stats-read.controller';
import { StatsReadService } from './stats-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [StatsReadController, EliteStatsReadController],
  providers: [StatsReadService, EliteStatsReadService],
})
export class StatsReadModule {}
