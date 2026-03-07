/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type VetoDraftStartDto = {
    /**
     * Veto/draft mode: "matchmaking_vote" or "tournament_draft"
     */
    mode: string;
    /**
     * Duration in seconds for the matchmaking vote phase
     */
    duration_seconds?: number;
    /**
     * Login of captain for Team A (required in tournament_draft mode)
     */
    captain_a?: string;
    /**
     * Login of captain for Team B (required in tournament_draft mode)
     */
    captain_b?: string;
    /**
     * Best-of target for the series (1, 3, 5)
     */
    best_of?: number;
    /**
     * Which team starts the veto: "team_a", "team_b", or "random"
     */
    starter?: string;
    /**
     * Timeout in seconds for each action in tournament_draft mode
     */
    action_timeout_seconds?: number;
    /**
     * If true, launch the first map immediately when veto completes
     */
    launch_immediately?: boolean;
};

