/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class LifecycleService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get current lifecycle state
     * Returns the current lifecycle state of the server, reconstructed from the latest lifecycle events for each phase (match, map, round, warmup, pause). Derives the current_phase from the most recent lifecycle event variant. Warmup and pause active status is determined from the latest warmup/pause event's phase (start vs end).
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Lifecycle state returned. Fields: server_login, current_phase, match/map/round (each: state, variant, source_time, event_id), warmup/pause (each: active, last_variant, source_time), last_updated (ISO8601). Null fields if no lifecycle events have been received.
     * @throws ApiError
     */
    public lifecycleReadControllerGetLifecycleState(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/lifecycle',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get map rotation and veto state
     * Returns the current map rotation state extracted from the latest lifecycle event containing map_rotation data (typically map.begin and map.end events). Includes: map pool, current map, next maps, played map order, series targets, and veto/draft state (mode, session status, actions, result). If no map rotation data exists, returns 200 with no_rotation_data: true.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Map rotation returned. Fields: server_login, map_pool (array), map_pool_size, current_map, current_map_index, next_maps, played_map_order, played_map_count, series_targets, veto { mode, session_status, ready_armed, actions, result, lifecycle }, source_time (ISO8601), event_id. If no data: no_rotation_data: true.
     * @throws ApiError
     */
    public lifecycleReadControllerGetMapRotation(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/lifecycle/map-rotation',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get latest aggregate stats
     * Returns the latest aggregate stats snapshots from lifecycle end events (round.end, map.end). Each aggregate includes: scope (round/map), counter_scope, player_counters_delta, totals, team_counters_delta, team_summary, tracked_player_count, window (time range), source_coverage, and win_context (result_state, winning_side, winning_reason). Filter by scope=round or scope=map to return only one scope's latest aggregate.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param scope Filter aggregates by scope: "round" or "map". Omit to return both.
     * @returns any Aggregate stats returned. Fields: server_login, aggregates (array). Each aggregate: scope, counter_scope, player_counters_delta, totals, team_counters_delta, team_summary, tracked_player_count, window, source_coverage, win_context, source_time, event_id.
     * @throws ApiError
     */
    public lifecycleReadControllerGetAggregateStats(
        serverLogin: string,
        scope?: 'round' | 'map',
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/lifecycle/aggregate-stats',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'scope': scope,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
