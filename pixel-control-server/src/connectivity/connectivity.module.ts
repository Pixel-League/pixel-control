import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';

import { ConnectivityService } from './connectivity.service';

/**
 * ConnectivityModule: provides ConnectivityService for backward-compat ConnectivityEvent table writes.
 * The POST /plugin/events endpoint has been moved to IngestionModule (IngestionController).
 * ConnectivityController is kept as a class but not registered as a controller here —
 * it is used only in unit tests for direct controller testing of the legacy P0 ingestEvent API.
 */
@Module({
  imports: [ConfigModule],
  providers: [ConnectivityService],
  exports: [ConnectivityService],
})
export class ConnectivityModule {}
