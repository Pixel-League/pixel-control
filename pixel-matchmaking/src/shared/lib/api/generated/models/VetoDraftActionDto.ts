/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type VetoDraftActionDto = {
    /**
     * Login of the player performing the action (captain or voter)
     */
    actor_login: string;
    /**
     * Operation type: "ban" or "pick" (tournament_draft only)
     */
    operation?: string;
    /**
     * UID of the map to ban/pick/vote for
     */
    map?: string;
    /**
     * Selection alias (e.g. "random")
     */
    selection?: string;
    /**
     * If true, overrides an existing vote (matchmaking mode)
     */
    allow_override?: boolean;
    /**
     * If true, forces the action even if not the actor's turn
     */
    force?: boolean;
};

