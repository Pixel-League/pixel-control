import { Injectable } from '@nestjs/common';

import { VetoDraftProxyService } from '../veto-draft-proxy/veto-draft-proxy.service';
import {
  VetoDraftActionDto,
  VetoDraftCancelDto,
  VetoDraftCommandResponse,
  VetoDraftStartDto,
  VetoDraftStatusResponse,
} from '../veto-draft-proxy/dto/veto-draft.dto';

/**
 * Service for VetoDraft flow commands (P4.1--P4.5).
 * Delegates to VetoDraftProxyService for socket communication and auth injection.
 */
@Injectable()
export class VetoDraftService {
  constructor(private readonly vetoDraftProxy: VetoDraftProxyService) {}

  getStatus(serverLogin: string): Promise<VetoDraftStatusResponse> {
    return this.vetoDraftProxy.queryVetoDraftStatus(serverLogin);
  }

  armReady(serverLogin: string): Promise<VetoDraftCommandResponse | VetoDraftStatusResponse> {
    return this.vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Ready');
  }

  startSession(
    serverLogin: string,
    dto: VetoDraftStartDto,
  ): Promise<VetoDraftCommandResponse | VetoDraftStatusResponse> {
    return this.vetoDraftProxy.sendVetoDraftCommand(
      serverLogin,
      'Start',
      dto as unknown as Record<string, unknown>,
    );
  }

  submitAction(
    serverLogin: string,
    dto: VetoDraftActionDto,
  ): Promise<VetoDraftCommandResponse | VetoDraftStatusResponse> {
    return this.vetoDraftProxy.sendVetoDraftCommand(
      serverLogin,
      'Action',
      dto as unknown as Record<string, unknown>,
    );
  }

  cancelSession(
    serverLogin: string,
    dto?: VetoDraftCancelDto,
  ): Promise<VetoDraftCommandResponse | VetoDraftStatusResponse> {
    return this.vetoDraftProxy.sendVetoDraftCommand(
      serverLogin,
      'Cancel',
      dto as Record<string, unknown> | undefined,
    );
  }
}
