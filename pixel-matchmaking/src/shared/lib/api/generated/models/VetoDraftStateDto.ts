/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type VetoDraftStateDto = {
    /**
     * Active veto/draft session data, or null when idle
     */
    session?: Record<string, any> | null;
    /**
     * Whether the matchmaking ready gate has been armed
     */
    matchmaking_ready_armed: boolean;
    /**
     * Matchmaking votes map: actor_login -> map_uid
     */
    votes: Record<string, any>;
};

