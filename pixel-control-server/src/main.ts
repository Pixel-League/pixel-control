import { ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';

import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter(),
  );

  app.setGlobalPrefix('v1');

  // CORS — configured via env vars
  const corsOrigin = process.env.CORS_ORIGIN || '*';
  const corsMethods = process.env.CORS_METHODS || 'GET,POST,PUT,DELETE,PATCH,OPTIONS,HEAD';
  app.enableCors({
    origin: corsOrigin === '*' ? '*' : corsOrigin.split(',').map((o) => o.trim()),
    methods: corsMethods.split(',').map((m) => m.trim()),
    credentials: process.env.CORS_CREDENTIALS === 'true',
  });

  app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true }));

  const swaggerConfig = new DocumentBuilder()
    .setTitle('Pixel Control API')
    .setDescription(
      'Central API server for orchestrating ManiaPlanet/ShootMania game servers',
    )
    .setVersion('0.1.0')
    .build();

  const document = SwaggerModule.createDocument(app, swaggerConfig);
  SwaggerModule.setup('api/docs', app, document, {
    jsonDocumentUrl: 'api/docs-json',
  });

  await app.listen(3000, '0.0.0.0');
}

bootstrap();
