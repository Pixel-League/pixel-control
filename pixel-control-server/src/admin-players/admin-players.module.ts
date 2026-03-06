import { Module } from '@nestjs/common';

import { AdminProxyModule } from '../admin-proxy/admin-proxy.module';
import { AdminPlayersController } from './admin-players.controller';
import { AdminPlayersService } from './admin-players.service';

@Module({
  imports: [AdminProxyModule],
  controllers: [AdminPlayersController],
  providers: [AdminPlayersService],
})
export class AdminPlayersModule {}
