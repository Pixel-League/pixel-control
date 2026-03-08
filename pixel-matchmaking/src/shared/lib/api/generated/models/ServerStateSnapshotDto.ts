/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { AdminStateDto } from './AdminStateDto';
import type { VetoDraftStateDto } from './VetoDraftStateDto';
export type ServerStateSnapshotDto = {
    /**
     * State schema version
     */
    state_version: string;
    /**
     * Unix timestamp when snapshot was captured
     */
    captured_at: number;
    /**
     * Admin runtime state
     */
    admin: AdminStateDto;
    /**
     * Veto/draft session state
     */
    veto_draft: VetoDraftStateDto;
};

