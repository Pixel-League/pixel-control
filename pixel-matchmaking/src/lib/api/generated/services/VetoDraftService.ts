/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { VetoDraftActionDto } from '../models/VetoDraftActionDto';
import type { VetoDraftCancelDto } from '../models/VetoDraftCancelDto';
import type { VetoDraftStartDto } from '../models/VetoDraftStartDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class VetoDraftService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get veto/draft session status
     * Returns the current state of the veto/draft session: idle, running, completed, or cancelled. Includes session details, map pool state, votes, and communication method names.
     * @param serverLogin Server login (unique identifier)
     * @returns any Veto status returned successfully.
     * @throws ApiError
     */
    public vetoDraftControllerGetStatus(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/veto/status',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Arm the matchmaking ready gate
     * Arms the matchmaking ready gate so a subsequent Start (matchmaking_vote mode) can proceed. Idempotent: calling twice returns "matchmaking_ready_already_armed".
     * @param serverLogin Server login (unique identifier)
     * @returns any Ready gate armed successfully.
     * @throws ApiError
     */
    public vetoDraftControllerArmReady(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/veto/ready',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Start a veto/draft session
     * Starts a new veto or matchmaking-vote session. For "tournament_draft" mode, captain_a and captain_b are required. For "matchmaking_vote" mode, the ready gate must be armed first.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Veto session started successfully.
     * @throws ApiError
     */
    public vetoDraftControllerStartSession(
        serverLogin: string,
        requestBody: VetoDraftStartDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/veto/start',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid parameters (missing mode, captain conflict, etc.).`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Submit a veto/draft action (ban, pick, or vote)
     * Submits a ban, pick, or vote action for the given actor. For tournament_draft: actor must be the current team captain. For matchmaking_vote: any player can vote.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Action submitted successfully.
     * @throws ApiError
     */
    public vetoDraftControllerSubmitAction(
        serverLogin: string,
        requestBody: VetoDraftActionDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/veto/action',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid parameters (missing actor_login, session not running, etc.).`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Cancel the active veto/draft session
     * Cancels the currently running veto or matchmaking-vote session. Returns "session_not_running" if no session is active.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Veto session cancelled (or was already idle).
     * @throws ApiError
     */
    public vetoDraftControllerCancelSession(
        serverLogin: string,
        requestBody?: VetoDraftCancelDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/veto/cancel',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
}
