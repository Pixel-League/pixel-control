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
import { AdminPlayersModule } from './admin-players/admin-players.module';
import { AdminTeamsModule } from './admin-teams/admin-teams.module';
import { VetoDraftProxyModule } from './veto-draft-proxy/veto-draft-proxy.module';
import { VetoDraftModule } from './veto-draft/veto-draft.module';
import { AdminAuthModule } from './admin-auth/admin-auth.module';
import { AdminWhitelistModule } from './admin-whitelist/admin-whitelist.module';
import { AdminVotesModule } from './admin-votes/admin-votes.module';
import { ServerStateModule } from './server-state/server-state.module';
import { ConfigTemplateModule } from './config-template/config-template.module';

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
    VetoDraftProxyModule,
    VetoDraftModule,
    AdminPlayersModule,
    AdminTeamsModule,
    AdminAuthModule,
    AdminWhitelistModule,
    AdminVotesModule,
    ServerStateModule,
    ConfigTemplateModule,
  ],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}
