import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { PrismaModule } from '../prisma/prisma.module';
import { LifecycleReadController } from './lifecycle-read.controller';
import { LifecycleReadService } from './lifecycle-read.service';

@Module({
  imports: [CommonModule, PrismaModule],
  controllers: [LifecycleReadController],
  providers: [LifecycleReadService],
})
export class LifecycleReadModule {}
