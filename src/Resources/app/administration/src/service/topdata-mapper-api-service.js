/**
 * API client for the mapper's admin action routes.
 *
 * The settings page never writes config storage directly — saveStrategy() is
 * the authoritative write gate (grammar + pairing matrix + provider existence
 * are validated server-side before any SystemConfigService write).
 *
 * 08/2026 created
 */
/* global Shopware */
/* eslint-disable no-undef */

const ApiService = Shopware.Classes.ApiService;

class TopdataMapperApiService extends ApiService {
    /**
     * @param {Object} httpClient - The HTTP client for making requests.
     * @param {Object} loginService - The login service for authentication.
     * @param {string} [apiEndpoint=''] - Base path segment ('' → /api).
     */
    constructor(httpClient, loginService, apiEndpoint = '') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'topdataMapperApiService';
    }

    /**
     * Module init: current DSL, presets, pairing matrix, credential status, providers.
     */
    getStrategy() {
        return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/strategy', {
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Authoritative strategy write gate. 400 → {error: {message, shopField, dimension, position}}.
     *
     * @param {string} dsl
     */
    saveStrategy(dsl) {
        return this.httpClient.post(this.getApiBasePath() + '/_action/topdata-mapper/strategy', { dsl }, {
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Debounced live validation → {valid, ast, error}.
     *
     * @param {string} dsl
     */
    validateStrategy(dsl) {
        return this.httpClient.post(this.getApiBasePath() + '/_action/topdata-mapper/validate-strategy', { dsl }, {
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Server-side paginated/filtered conflict list.
     */
    fetchConflicts(params) {
        return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/conflicts', {
            params,
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Server-side paginated/filtered product mappings.
     */
    fetchMappings(params) {
        return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/mappings', {
            params,
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Server-side paginated/filtered brand mappings.
     */
    fetchBrands(params) {
        return this.httpClient.get(this.getApiBasePath() + '/_action/topdata-mapper/brands', {
            params,
            headers: this.getBasicHeaders(),
        });
    }

    /**
     * Resolves a conflict without re-import (immediate, status 'user').
     *
     * @param {string} productId - 32-char hex product id
     * @param {number} chosenTopdataProductId
     */
    resolveConflict(productId, chosenTopdataProductId) {
        return this.httpClient.post(this.getApiBasePath() + '/_action/topdata-mapper/resolve-conflict', {
            productId,
            chosenTopdataProductId,
        }, {
            headers: this.getBasicHeaders(),
        });
    }
}

export default TopdataMapperApiService;
