import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { ModeReadController } from './mode-read.controller';
import { ModeReadService } from './mode-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [ModeReadController],
  providers: [ModeReadService],
})
export class ModeReadModule {}
