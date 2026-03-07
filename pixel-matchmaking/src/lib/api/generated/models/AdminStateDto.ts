/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type AdminStateDto = {
    /**
     * Current best-of setting
     */
    current_best_of: number;
    /**
     * Team maps score snapshot
     */
    team_maps_score: Record<string, any>;
    /**
     * Team round score snapshot
     */
    team_round_score: Record<string, any>;
    /**
     * Whether team policy (force-team restrictions) is enabled
     */
    team_policy_enabled: boolean;
    /**
     * Whether team switch lock is active
     */
    team_switch_lock: boolean;
    /**
     * Team roster mapping: login -> team_a|team_b
     */
    team_roster: Record<string, any>;
    /**
     * Whether the server whitelist is enabled
     */
    whitelist_enabled: boolean;
    /**
     * List of whitelisted player logins
     */
    whitelist: Array<string>;
    /**
     * Current vote policy mode
     */
    vote_policy: string;
    /**
     * Per-command vote ratios: command -> float 0-1
     */
    vote_ratios: Record<string, any>;
};

