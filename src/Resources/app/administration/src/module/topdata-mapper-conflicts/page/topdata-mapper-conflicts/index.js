import template from './topdata-mapper-conflicts.html.twig';
import './topdata-mapper-conflicts.scss';

const { Component, Mixin } = Shopware;

/**
 * Card C — conflict resolution (Products navigation).
 *
 * Server-side pagination/filtering via route params (conflicts can be
 * numerous); radio pick = immediate POST resolve-conflict, no re-import.
 * Candidate previews render from the stored JSON — zero API traffic.
 *
 * 08/2026 created
 */
Component.register('topdata-mapper-conflicts', {
    template,

    inject: ['TopdataMapperApiService'],
    mixins: [Mixin.getByName('notification')],

    data: () => ({
        rows: [],
        total: 0,
        page: 1,
        limit: 25,
        status: 'all',
        search: '',
        stats: { pending: 0, resolved: 0, lastImportAt: null },
        isLoading: true,
        resolvingKey: null,
    }),

    computed: {
        columns() {
            return [
                { property: 'productNumber', label: this.$tc('TopdataMapperSW6.conflicts.columns.product'), sortable: false },
                { property: 'candidates', label: this.$tc('TopdataMapperSW6.conflicts.columns.candidates'), sortable: false },
                { property: 'status', label: this.$tc('TopdataMapperSW6.conflicts.columns.status'), sortable: false },
                { property: 'updatedAt', label: this.$tc('TopdataMapperSW6.conflicts.columns.updatedAt'), sortable: false },
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
            return this.TopdataMapperApiService.fetchConflicts({
                page: this.page,
                limit: this.limit,
                status: this.status,
                search: this.search,
            })
                .then((response) => {
                    this.rows = response.data.rows;
                    this.total = response.data.total;
                    this.stats = response.data.stats;
                })
                .finally(() => {
                    this.isLoading = false;
                });
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

        onStatusChange(statusOrItem) {
            this.status = typeof statusOrItem === 'string' ? statusOrItem : statusOrItem.name;
            this.page = 1;
            this.load();
        },

        /**
         * Immediate POST on radio pick; row is updated in place so the grid
         * reflects the new resolution without a re-import.
         */
        resolve(row, candidate) {
            if (candidate.id === row.chosenTopdataProductId) {
                return;
            }
            this.resolvingKey = row.productId + ':' + candidate.id;
            this.TopdataMapperApiService.resolveConflict(row.productId, candidate.id)
                .then(() => {
                    row.chosenTopdataProductId = candidate.id;
                    row.status = 'user';
                    this.stats.pending = Math.max(0, this.stats.pending - 1);
                    this.stats.resolved += 1;
                    this.createNotificationSuccess({
                        title: this.$tc('TopdataMapperSW6.conflicts.resolve.successTitle'),
                        message: this.$tc('TopdataMapperSW6.conflicts.resolve.successMessage', { number: candidate.id }),
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('TopdataMapperSW6.conflicts.resolve.failedTitle'),
                        message: this.$tc('TopdataMapperSW6.conflicts.resolve.failedMessage'),
                    });
                })
                .finally(() => {
                    this.resolvingKey = null;
                });
        },

        statusLabel(status) {
            return status === 'user'
                ? this.$tc('TopdataMapperSW6.conflicts.status.resolved')
                : this.$tc('TopdataMapperSW6.conflicts.status.pending');
        },

        statusVariant(status) {
            return status === 'user' ? 'success' : 'warning';
        },

        candidateHint(candidate) {
            const previews = [];
            if (candidate.pcd && candidate.pcd.length > 0) {
                previews.push(candidate.pcd.join(', '));
            }
            if (candidate.ean && candidate.ean.length > 0) {
                previews.push(candidate.ean.join(', '));
            }
            if (candidate.mpn && candidate.mpn.length > 0) {
                previews.push(candidate.mpn.join(', '));
            }

            return previews.join(' · ');
        },
    },
});