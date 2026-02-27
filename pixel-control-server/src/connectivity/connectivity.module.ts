import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { ConnectivityController } from './connectivity.controller';
import { ConnectivityService } from './connectivity.service';

@Module({
  imports: [ConfigModule],
  controllers: [ConnectivityController],
  providers: [ConnectivityService],
})
export class ConnectivityModule {}
