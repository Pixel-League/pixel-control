import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Post,
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
import {
  CreateConfigTemplateDto,
  UpdateConfigTemplateDto,
} from './dto/config-template.dto';

@ApiTags('Config Templates')
@Controller('config-templates')
export class ConfigTemplateController {
  constructor(private readonly configTemplateService: ConfigTemplateService) {}

  // ─── POST — Create template ─────────────────────────────────────────────

  @ApiOperation({
    summary: 'Create a configuration template',
    description: 'Creates a new reusable admin configuration template.',
  })
  @ApiBody({ type: CreateConfigTemplateDto })
  @ApiResponse({ status: 201, description: 'Template created.' })
  @ApiResponse({ status: 400, description: 'Invalid body.' })
  @ApiResponse({ status: 409, description: 'Template name already in use.' })
  @Post()
  @HttpCode(201)
  create(@Body() body: CreateConfigTemplateDto) {
    return this.configTemplateService.create(body);
  }

  // ─── GET — List all templates ───────────────────────────────────────────

  @ApiOperation({
    summary: 'List all configuration templates',
    description: 'Returns all configuration templates with their linked server counts.',
  })
  @ApiResponse({ status: 200, description: 'Template list returned.' })
  @Get()
  @HttpCode(200)
  findAll() {
    return this.configTemplateService.findAll();
  }

  // ─── GET — Single template ──────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get a configuration template by ID',
    description: 'Returns a single configuration template with its linked server count.',
  })
  @ApiParam({ name: 'id', description: 'Template UUID' })
  @ApiResponse({ status: 200, description: 'Template returned.' })
  @ApiResponse({ status: 404, description: 'Template not found.' })
  @Get(':id')
  @HttpCode(200)
  findOne(@Param('id') id: string) {
    return this.configTemplateService.findOne(id);
  }

  // ─── PUT — Update template ──────────────────────────────────────────────

  @ApiOperation({
    summary: 'Update a configuration template',
    description: 'Partially updates an existing configuration template.',
  })
  @ApiParam({ name: 'id', description: 'Template UUID' })
  @ApiBody({ type: UpdateConfigTemplateDto })
  @ApiResponse({ status: 200, description: 'Template updated.' })
  @ApiResponse({ status: 404, description: 'Template not found.' })
  @ApiResponse({ status: 409, description: 'Template name already in use.' })
  @Put(':id')
  @HttpCode(200)
  update(@Param('id') id: string, @Body() body: UpdateConfigTemplateDto) {
    return this.configTemplateService.update(id, body);
  }

  // ─── DELETE — Delete template ───────────────────────────────────────────

  @ApiOperation({
    summary: 'Delete a configuration template',
    description:
      'Deletes a configuration template. Returns 409 Conflict if servers are still linked.',
  })
  @ApiParam({ name: 'id', description: 'Template UUID' })
  @ApiResponse({ status: 200, description: 'Template deleted.' })
  @ApiResponse({ status: 404, description: 'Template not found.' })
  @ApiResponse({ status: 409, description: 'Servers are still linked to this template.' })
  @Delete(':id')
  @HttpCode(200)
  remove(@Param('id') id: string) {
    return this.configTemplateService.remove(id);
  }
}
