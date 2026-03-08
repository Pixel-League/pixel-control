/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { EventEnvelopeDto } from '../models/EventEnvelopeDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class PluginEventsService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Receive all event categories from plugin
     * Unified ingestion endpoint for all plugin event categories: connectivity, lifecycle, combat, player, mode, and batch. Validates, deduplicates (via idempotency_key), and stores each event in the unified Event table. Connectivity events are additionally written to the ConnectivityEvent table for backward compatibility. Auto-registers unknown servers on first event. Accepts both the plugin wrapped format { envelope: {...}, transport: {...} } and flat envelope format for backward compatibility. Batch events (event_category="batch") unpack payload.events and process each inner envelope individually.
     * @param xPixelServerLogin Dedicated server login sending the event
     * @param requestBody Standard event envelope. event_category determines routing. Plugin wraps envelopes as { "envelope": {...}, "transport": {...} }; both formats are accepted.
     * @param xPixelPluginVersion Plugin version string (e.g. "1.0.0")
     * @returns any Event accepted. Returns { ack: { status: "accepted" } } or { ack: { status: "accepted", disposition: "duplicate" } } for duplicates. Batch events return { ack: { status: "accepted", batch_size: N, accepted: M, duplicates: D, rejected: R } }.
     * @throws ApiError
     */
    public ingestionControllerIngestEvent(
        xPixelServerLogin: string,
        requestBody: EventEnvelopeDto,
        xPixelPluginVersion?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/plugin/events',
            headers: {
                'X-Pixel-Plugin-Version': xPixelPluginVersion,
                'X-Pixel-Server-Login': xPixelServerLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Rejected — missing X-Pixel-Server-Login header or invalid envelope. Returns { ack: { status: "rejected", code: "missing_server_login"|"invalid_envelope", retryable: false } }.`,
                500: `Internal error. Returns { error: { code: "internal_error", retryable: true, retry_after_seconds: 5 } }.`,
            },
        });
    }
}
