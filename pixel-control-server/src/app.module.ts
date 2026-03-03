import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { AppController } from './app.controller';
import { AppService } from './app.service';
import { AdminMapsModule } from './admin-maps/admin-maps.module';
import { AdminMatchModule } from './admin-match/admin-match.module';
import { AdminProxyModule } from './admin-proxy/admin-proxy.module';
import { AdminWarmupPauseModule } from './admin-warmup-pause/admin-warmup-pause.module';
import { CommonModule } from './common/common.module';
import { IngestionModule } from './ingestion/ingestion.module';
import { LifecycleReadModule } from './lifecycle/lifecycle-read.module';
import { LinkModule } from './link/link.module';
import { MapsReadModule } from './maps/maps-read.module';
import { ModeReadModule } from './mode/mode-read.module';
import { PlayersReadModule } from './players/players-read.module';
import { PrismaModule } from './prisma/prisma.module';
import { StatsReadModule } from './stats/stats-read.module';
import { StatusModule } from './status/status.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    PrismaModule,
    CommonModule,
    LinkModule,
    IngestionModule,
    StatusModule,
    PlayersReadModule,
    StatsReadModule,
    LifecycleReadModule,
    MapsReadModule,
    ModeReadModule,
    AdminProxyModule,
    AdminMapsModule,
    AdminWarmupPauseModule,
    AdminMatchModule,
  ],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}
