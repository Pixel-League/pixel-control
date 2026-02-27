import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { LinkController } from './link.controller';
import { LinkService } from './link.service';

@Module({
  imports: [ConfigModule],
  controllers: [LinkController],
  providers: [LinkService],
  exports: [LinkService],
})
export class LinkModule {}
