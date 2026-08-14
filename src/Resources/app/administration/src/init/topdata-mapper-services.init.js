import TopdataMapperApiService from '../service/topdata-mapper-api-service';

/**
 * Fix for "TS2304: Cannot find name Shopware"
 */
/* global Shopware */
/* eslint-disable no-undef */

Shopware.Service().register('TopdataMapperApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new TopdataMapperApiService(initContainer.httpClient, container.loginService);
});
