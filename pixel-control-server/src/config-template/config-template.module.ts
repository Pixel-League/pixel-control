import { Module } from '@nestjs/common';

import { CommonModule } from '../common/common.module';
import { ConfigTemplateController } from './config-template.controller';
import { ServerConfigTemplateController } from './server-config-template.controller';
import { ConfigTemplateService } from './config-template.service';

@Module({
  imports: [CommonModule],
  controllers: [ConfigTemplateController, ServerConfigTemplateController],
  providers: [ConfigTemplateService],
  exports: [ConfigTemplateService],
})
export class ConfigTemplateModule {}
