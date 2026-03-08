/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type TeamRosterAssignDto = {
    /**
     * Login of the player to assign to a team
     */
    target_login: string;
    /**
     * Team to assign: "team_a", "team_b", "a", "b", "0", "1", "red", or "blue"
     */
    team: string;
};

