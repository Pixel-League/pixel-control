import { Controller, Get } from '@nestjs/common';
import { ApiOperation, ApiResponse, ApiTags } from '@nestjs/swagger';

import { AppService } from './app.service';

@ApiTags('Health')
@Controller()
export class AppController {
  constructor(private readonly appService: AppService) {}

  @ApiOperation({
    summary: 'Health check',
    description:
      'Returns API health status. Use this to verify the server is running.',
  })
  @ApiResponse({ status: 200, description: 'API is healthy.' })
  @Get()
  healthCheck() {
    return this.appService.healthCheck();
  }
}
