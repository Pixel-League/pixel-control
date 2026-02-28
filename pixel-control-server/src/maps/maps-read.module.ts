import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { MapsReadController } from './maps-read.controller';
import { MapsReadService } from './maps-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [MapsReadController],
  providers: [MapsReadService],
})
export class MapsReadModule {}
