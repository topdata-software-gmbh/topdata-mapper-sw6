// ---- page ----
import './page/topdata-mapper-mappings';

// ---- snippets ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module ----
Shopware.Module.register('topdata-mapper-mappings', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.mappings.title',
    description: 'TopdataMapperSW6.mappings.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    routes: {
        index: {
            component: 'topdata-mapper-mappings',
            path: 'mappings',
            meta: {
                privilege: 'topdata_mapper:read',
            },
        },
    },

    navigation: [
        {
            label: 'TopdataMapperSW6.mappings.title',
            color: '#ff3d58',
            path: 'topdata.mapper.mappings.index',
            icon: 'regular-list',
            position: 10,
            parent: 'topdata-mapper',
            privilege: 'topdata_mapper:read',
        },
    ],
});