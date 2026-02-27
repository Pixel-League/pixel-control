import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { AppController } from './app.controller';
import { AppService } from './app.service';
import { ConnectivityModule } from './connectivity/connectivity.module';
import { LinkModule } from './link/link.module';
import { PrismaModule } from './prisma/prisma.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    PrismaModule,
    LinkModule,
    ConnectivityModule,
  ],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}
