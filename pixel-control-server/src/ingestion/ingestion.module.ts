import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { ConnectivityModule } from '../connectivity/connectivity.module';
import { PrismaModule } from '../prisma/prisma.module';
import { IngestionController } from './ingestion.controller';
import { IngestionService } from './ingestion.service';
import { BatchService } from './services/batch.service';
import { CombatService } from './services/combat.service';
import { LifecycleService } from './services/lifecycle.service';
import { ModeService } from './services/mode.service';
import { PlayerService } from './services/player.service';

@Module({
  imports: [ConfigModule, PrismaModule, ConnectivityModule],
  controllers: [IngestionController],
  providers: [
    IngestionService,
    LifecycleService,
    CombatService,
    PlayerService,
    ModeService,
    BatchService,
  ],
})
export class IngestionModule {}
