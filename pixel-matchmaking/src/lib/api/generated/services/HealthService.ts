/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class HealthService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Health check
     * Returns API health status. Use this to verify the server is running.
     * @returns any API is healthy.
     * @throws ApiError
     */
    public appControllerHealthCheck(): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1',
        });
    }
}
