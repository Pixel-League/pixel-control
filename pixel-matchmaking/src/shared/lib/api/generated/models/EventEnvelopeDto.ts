/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
export type EventEnvelopeDto = {
    event_name: string;
    schema_version: string;
    event_id: string;
    event_category: string;
    source_callback: string;
    source_sequence: number;
    source_time: number;
    idempotency_key: string;
    payload: Record<string, any>;
    metadata?: Record<string, any>;
};

