/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { LinkServerToTemplateDto } from '../models/LinkServerToTemplateDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class ServerConfigTemplateService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Link a server to a configuration template
     * Associates the server with a configuration template. The template config is used as fallback when no saved state exists.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Server linked to template.
     * @throws ApiError
     */
    public serverConfigTemplateControllerLinkServerToTemplate(
        serverLogin: string,
        requestBody: LinkServerToTemplateDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/config-template',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                404: `Server or template not found.`,
            },
        });
    }
    /**
     * Unlink a server from its configuration template
     * Removes the template association from the server.
     * @param serverLogin Server login (unique identifier)
     * @returns any Server unlinked from template.
     * @throws ApiError
     */
    public serverConfigTemplateControllerUnlinkServer(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/config-template',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get the linked configuration template for a server
     * Returns the template linked to the server, or null if none is linked.
     * @param serverLogin Server login (unique identifier)
     * @returns any Template returned (or null).
     * @throws ApiError
     */
    public serverConfigTemplateControllerGetServerTemplate(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/config-template',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
