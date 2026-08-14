// ---- page ----
import './page/topdata-mapper-conflicts';

// ---- snippets ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module ----
Shopware.Module.register('topdata-mapper-conflicts', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.conflicts.title',
    description: 'TopdataMapperSW6.conflicts.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    routes: {
        index: {
            component: 'topdata-mapper-conflicts',
            path: 'conflicts',
            meta: {
                parentPath: 'sw.product.index',
                privilege: 'topdata_mapper:read',
            },
        },
    },

    navigation: [
        {
            label: 'TopdataMapperSW6.conflicts.title',
            color: '#ff3d58',
            path: 'topdata.mapper.conflicts.index',
            icon: 'regular-plug',
            position: 20,
            parent: 'sw-product',
        },
    ],
});