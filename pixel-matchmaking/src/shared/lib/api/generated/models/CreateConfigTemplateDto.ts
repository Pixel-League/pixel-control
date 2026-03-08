/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { AdminConfigDto } from './AdminConfigDto';
export type CreateConfigTemplateDto = {
    /**
     * Template name (unique)
     */
    name: string;
    /**
     * Optional description
     */
    description?: string;
    /**
     * Full admin configuration
     */
    config: AdminConfigDto;
};

