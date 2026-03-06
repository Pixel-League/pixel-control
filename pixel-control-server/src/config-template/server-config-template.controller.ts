import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Put,
} from '@nestjs/common';
import {
  ApiBody,
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { ConfigTemplateService } from './config-template.service';
import { LinkServerToTemplateDto } from './dto/config-template.dto';

@ApiTags('Server Config Template')
@Controller('servers')
export class ServerConfigTemplateController {
  constructor(private readonly configTemplateService: ConfigTemplateService) {}

  // ─── PUT — Link server to template ──────────────────────────────────────

  @ApiOperation({
    summary: 'Link a server to a configuration template',
    description:
      'Associates the server with a configuration template. The template config is used as fallback when no saved state exists.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)' })
  @ApiBody({ type: LinkServerToTemplateDto })
  @ApiResponse({ status: 200, description: 'Server linked to template.' })
  @ApiResponse({ status: 404, description: 'Server or template not found.' })
  @Put(':serverLogin/config-template')
  @HttpCode(200)
  linkServerToTemplate(
    @Param('serverLogin') serverLogin: string,
    @Body() body: LinkServerToTemplateDto,
  ) {
    return this.configTemplateService.linkServerToTemplate(serverLogin, body.template_id);
  }

  // ─── DELETE — Unlink server from template ───────────────────────────────

  @ApiOperation({
    summary: 'Unlink a server from its configuration template',
    description: 'Removes the template association from the server.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)' })
  @ApiResponse({ status: 200, description: 'Server unlinked from template.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Delete(':serverLogin/config-template')
  @HttpCode(200)
  unlinkServer(@Param('serverLogin') serverLogin: string) {
    return this.configTemplateService.unlinkServer(serverLogin);
  }

  // ─── GET — Get linked template for server ───────────────────────────────

  @ApiOperation({
    summary: 'Get the linked configuration template for a server',
    description: 'Returns the template linked to the server, or null if none is linked.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)' })
  @ApiResponse({ status: 200, description: 'Template returned (or null).' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/config-template')
  @HttpCode(200)
  getServerTemplate(@Param('serverLogin') serverLogin: string) {
    return this.configTemplateService.getServerTemplate(serverLogin);
  }
}
