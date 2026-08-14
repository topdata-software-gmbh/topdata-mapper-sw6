// ---- snippets (shared nav labels for children) ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module (navigation group with a real index route) ----
Shopware.Module.register('topdata-mapper', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.navigation.title',
    description: 'TopdataMapperSW6.navigation.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    routes: {
        index: {
            component: 'topdata-mapper-mappings',
            path: 'index',
            meta: {
                privilege: 'topdata_mapper:read',
            },
        },
    },

    navigation: [
        {
            id: 'topdata-mapper',
            label: 'TopdataMapperSW6.navigation.title',
            color: '#ff3d58',
            path: 'topdata.mapper.index',
            icon: 'regular-plug',
            position: 10,
            parent: 'sw-catalogue',
            privilege: 'topdata_mapper:read',
        },
    ],
});