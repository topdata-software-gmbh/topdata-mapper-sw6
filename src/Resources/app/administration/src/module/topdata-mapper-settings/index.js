// ---- page ----
import './page/topdata-mapper-settings/topdata-mapper-settings';

// ---- snippets ----
import './snippet/en-GB.json';
import './snippet/de-DE.json';

// ---- register module ----
Shopware.Module.register('topdata-mapper-settings', {
    type: 'plugin',
    name: 'TopdataMapperSW6',
    title: 'TopdataMapperSW6.settings.title',
    description: 'TopdataMapperSW6.settings.description',
    color: '#ff3d58',
    icon: 'regular-plug',

    settingsItem: [
        {
            group: 'plugins',
            to: 'topdata.mapper.settings',
            icon: 'regular-plug',
            name: 'TopdataMapperSW6.settings.title',
            label: 'TopdataMapperSW6.settings.title',
        },
    ],

    routes: {
        settings: {
            component: 'topdata-mapper-settings',
            path: 'settings',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'topdata_mapper:read',
            },
        },
    },
});
