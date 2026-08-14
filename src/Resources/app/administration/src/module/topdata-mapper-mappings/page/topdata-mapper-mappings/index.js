import template from './topdata-mapper-mappings.html.twig';
import './topdata-mapper-mappings.scss';

const { Component } = Shopware;

/**
 * Mappings browser (Topdata Mapper navigation group).
 *
 * Read-only grid over tdmp_product / tdmp_brand — server-side paginated and
 * searchable via the mapper API service. Two tabs: product mappings and brand
 * mappings.
 *
 * 08/2026 created
 */
Component.register('topdata-mapper-mappings', {
    template,

    inject: ['TopdataMapperApiService'],

    data: () => ({
        activeTab: 'products',
        rows: [],
        total: 0,
        page: 1,
        limit: 25,
        search: '',
        isLoading: true,
    }),

    computed: {
        columns() {
            if (this.activeTab === 'products') {
                return [
                    { property: 'productNumber', label: this.$tc('TopdataMapperSW6.mappings.columns.product'), sortable: false },
                    { property: 'topdataProductId', label: this.$tc('TopdataMapperSW6.mappings.columns.topdataId'), sortable: false },
                    { property: 'createdAt', label: this.$tc('TopdataMapperSW6.mappings.columns.createdAt'), sortable: false },
                    { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.mappings.columns.updatedAt'), sortable: false },
                ];
            }

            return [
                { property: 'manufacturerName', label: this.$tc('TopdataMapperSW6.mappings.columns.manufacturer'), sortable: false },
                { property: 'topdataBrandId', label: this.$tc('TopdataMapperSW6.mappings.columns.topdataId'), sortable: false },
                { property: 'createdAt', label: this.$tc('TopdataMapperSW6.mappings.columns.createdAt'), sortable: false },
                { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.mappings.columns.updatedAt'), sortable: false },
            ];
        },
    },

    created() {
        this.debouncedSearch = Shopware.Utils.debounce(this.load, 400);
        this.load();
    },

    methods: {
        load() {
            this.isLoading = true;

            const params = {
                page: this.page,
                limit: this.limit,
                search: this.search,
            };

            const request = this.activeTab === 'products'
                ? this.TopdataMapperApiService.fetchMappings(params)
                : this.TopdataMapperApiService.fetchBrands(params);

            return request
                .then((response) => {
                    this.rows = response.data.rows;
                    this.total = response.data.total;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onTabChange(tab) {
            this.activeTab = tab.name || tab;
            this.page = 1;
            this.search = '';
            this.load();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.load();
        },

        onLimitChange(limit) {
            this.limit = limit;
            this.page = 1;
            this.load();
        },

        onSearchChange() {
            this.page = 1;
            this.debouncedSearch();
        },
    },
});