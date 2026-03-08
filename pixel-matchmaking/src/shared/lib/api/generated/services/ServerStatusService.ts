/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class ServerStatusService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get server status
     * Returns the current status of a connected server, including online status, game mode, plugin version, player counts (from latest heartbeat), and event counts by category. Returns 404 if the server has never connected.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Server status returned. Fields: server_login, server_name, linked, online, game_mode, title_id, plugin_version, last_heartbeat (ISO8601), player_counts { active, total, spectators }, event_counts { total, by_category { connectivity, lifecycle, combat, player, mode } }.
     * @throws ApiError
     */
    public statusControllerGetServerStatus(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/status',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found — server has never connected or been registered.`,
            },
        });
    }
    /**
     * Get server plugin health
     * Returns plugin health metrics extracted from the latest connectivity heartbeat event. Includes queue depth, retry configuration, outage status, and connectivity event counts. Returns 404 if the server has never connected.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Plugin health returned. Fields: server_login, online, plugin_health { queue { depth, max_size, high_watermark, dropped_on_capacity, dropped_on_identity_validation, recovery_flush_pending }, retry { max_retry_attempts, retry_backoff_ms, dispatch_batch_size }, outage { active, started_at, failure_count, last_error_code, recovery_flush_pending } }, connectivity_metrics { total_connectivity_events, last_registration_at, last_heartbeat_at, heartbeat_count, registration_count }.
     * @throws ApiError
     */
    public statusControllerGetServerHealth(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/status/health',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found — server has never connected or been registered.`,
            },
        });
    }
    /**
     * Get plugin capabilities snapshot
     * Returns the plugin capabilities as reported during the most recent plugin registration event. Falls back to the latest heartbeat event if no registration event is available. Capabilities include: admin_control, queue, transport, callbacks, and any other fields declared by the plugin at startup. Returns capabilities: null if no connectivity data exists.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Capabilities snapshot returned. Fields: server_login, online, capabilities (object or null), source ("plugin_registration" | "plugin_heartbeat" | null), source_time (ISO8601 or null).
     * @throws ApiError
     */
    public statusControllerGetServerCapabilities(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/status/capabilities',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found — server has never connected or been registered.`,
            },
        });
    }
}
