/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CreateConfigTemplateDto } from '../models/CreateConfigTemplateDto';
import type { UpdateConfigTemplateDto } from '../models/UpdateConfigTemplateDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class ConfigTemplatesService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Create a configuration template
     * Creates a new reusable admin configuration template.
     * @param requestBody
     * @returns any Template created.
     * @throws ApiError
     */
    public configTemplateControllerCreate(
        requestBody: CreateConfigTemplateDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/config-templates',
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid body.`,
                409: `Template name already in use.`,
            },
        });
    }
    /**
     * List all configuration templates
     * Returns all configuration templates with their linked server counts.
     * @returns any Template list returned.
     * @throws ApiError
     */
    public configTemplateControllerFindAll(): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/config-templates',
        });
    }
    /**
     * Get a configuration template by ID
     * Returns a single configuration template with its linked server count.
     * @param id Template UUID
     * @returns any Template returned.
     * @throws ApiError
     */
    public configTemplateControllerFindOne(
        id: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/config-templates/{id}',
            path: {
                'id': id,
            },
            errors: {
                404: `Template not found.`,
            },
        });
    }
    /**
     * Update a configuration template
     * Partially updates an existing configuration template.
     * @param id Template UUID
     * @param requestBody
     * @returns any Template updated.
     * @throws ApiError
     */
    public configTemplateControllerUpdate(
        id: string,
        requestBody: UpdateConfigTemplateDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/config-templates/{id}',
            path: {
                'id': id,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                404: `Template not found.`,
                409: `Template name already in use.`,
            },
        });
    }
    /**
     * Delete a configuration template
     * Deletes a configuration template. Returns 409 Conflict if servers are still linked.
     * @param id Template UUID
     * @returns any Template deleted.
     * @throws ApiError
     */
    public configTemplateControllerRemove(
        id: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/config-templates/{id}',
            path: {
                'id': id,
            },
            errors: {
                404: `Template not found.`,
                409: `Servers are still linked to this template.`,
            },
        });
    }
}
