import template from './topdata-mapper-settings.html.twig';
import './topdata-mapper-settings.scss';

const { Component, Mixin } = Shopware;

/**
 * Card B — matching strategy editor.
 *
 * The DSL string is the single source of truth; the textarea is the only
 * editor (preset chips fill it, the help modal documents the grammar). The
 * PHP parser is authoritative — textarea edits are re-validated debounced via
 * validate-strategy.
 *
 * 08/2026 created, 08/2026 visual builder removed
 */
Component.register('topdata-mapper-settings', {
    template,

    inject: ['TopdataMapperApiService'],
    mixins: [Mixin.getByName('notification')],

    data: () => ({
        isLoading: true,
        isSaving: false,
        isCopying: false,

        // ---- DSL string (source of truth) ----
        dsl: '',
        lastLoadedDsl: '',

        // ---- module init data ----
        presets: [],
        allowedPairs: {},
        credentialsConfigured: false,

        // ---- validation state ----
        validationValid: true,
        validationError: null,

        // ---- dirty-check confirm (preset chip) ----
        showDirtyConfirm: false,
        pendingPreset: null,

        // ---- DSL syntax help modal ----
        showDslHelp: false,

        saveError: null,
    }),

    computed: {
        isDirty() {
            return this.dsl !== this.lastLoadedDsl;
        },

        /**
         * Preset chip highlight: a chip is active iff the current DSL equals
         * its canonical string; "Custom" is active iff no preset matches.
         */
        activePresetKey() {
            const matching = this.presets.find((preset) => preset.dsl !== null && preset.dsl === this.dsl);

            return matching ? matching.key : 'custom';
        },
    },

    created() {
        this.debouncedValidate = Shopware.Utils.debounce(this.validateDsl, 400);
        this.loadStrategy();
    },

    watch: {
        dsl() {
            this.debouncedValidate();
        },
    },

    methods: {
        // ---------------------------------------------------------------- init
        loadStrategy() {
            this.isLoading = true;
            this.TopdataMapperApiService.getStrategy()
                .then((response) => {
                    this.dsl = response.data.dsl;
                    this.lastLoadedDsl = response.data.dsl;
                    this.presets = response.data.presets;
                    this.allowedPairs = response.data.allowedPairs;
                    this.credentialsConfigured = response.data.credentialsConfigured;
                    return this.validateDsl();
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        // ------------------------------------------------------------- labels
        openDslHelp() {
            this.showDslHelp = true;
        },

        closeDslHelp() {
            this.showDslHelp = false;
        },

        dimensionLabel(dimension) {
            const labels = {
                'ean': this.$tc('TopdataMapperSW6.settings.dimension.ean'),
                'mpn': this.$tc('TopdataMapperSW6.settings.dimension.mpn'),
                'pcd': this.$tc('TopdataMapperSW6.settings.dimension.pcd'),
                'articleNumbers': this.$tc('TopdataMapperSW6.settings.dimension.articleNumbers'),
                'topdataBrandIds': this.$tc('TopdataMapperSW6.settings.dimension.topdataBrandIds'),
            };

            return labels[dimension] || dimension;
        },

        // ------------------------------------------------------------ presets
        applyPreset(preset) {
            this.dsl = preset.dsl;
            this.lastLoadedDsl = preset.dsl;
            this.saveError = null;
            this.validateDsl();
        },

        onPresetClick(preset) {
            if (preset.dsl === null) {
                return;
            }
            if (this.isDirty) {
                this.pendingPreset = preset;
                this.showDirtyConfirm = true;

                return;
            }
            this.applyPreset(preset);
        },

        confirmPreset() {
            this.showDirtyConfirm = false;
            if (this.pendingPreset) {
                this.applyPreset(this.pendingPreset);
            }
            this.pendingPreset = null;
        },

        cancelPreset() {
            this.showDirtyConfirm = false;
            this.pendingPreset = null;
        },

        // ---------------------------------------------------------- validation
        validateDsl() {
            return this.TopdataMapperApiService.validateStrategy(this.dsl)
                .then((response) => {
                    this.validationValid = response.data.valid;
                    this.validationError = response.data.error;
                })
                .catch(() => {
                    this.validationValid = false;
                    this.validationError = { message: this.$tc('TopdataMapperSW6.settings.validation.unreachable') };
                });
        },

        // --------------------------------------------------------------- save
        save() {
            this.isSaving = true;
            this.saveError = null;
            this.TopdataMapperApiService.saveStrategy(this.dsl)
                .then((response) => {
                    this.dsl = response.data.dsl;
                    this.lastLoadedDsl = response.data.dsl;
                    this.validationValid = true;
                    this.validationError = null;
                    this.createNotificationSuccess({
                        title: this.$tc('TopdataMapperSW6.settings.save.successTitle'),
                        message: this.$tc('TopdataMapperSW6.settings.save.successMessage'),
                    });
                })
                .catch((error) => {
                    if (error.response && error.response.data && error.response.data.error) {
                        this.saveError = error.response.data.error;
                    } else {
                        this.saveError = { message: this.$tc('TopdataMapperSW6.settings.save.failedGeneric') };
                    }
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },

        // --------------------------------------------------------------- copy
        copyDsl() {
            this.isCopying = true;
            const fallback = () => {
                const el = this.$refs.dslTextarea.$el.querySelector('textarea');
                el.select();
                document.execCommand('copy');
                this.isCopying = false;
                this.createNotificationInfo({
                    title: this.$tc('TopdataMapperSW6.settings.copy.title'),
                    message: this.$tc('TopdataMapperSW6.settings.copy.message'),
                });
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(this.dsl).then(() => {
                    this.isCopying = false;
                    this.createNotificationInfo({
                        title: this.$tc('TopdataMapperSW6.settings.copy.title'),
                        message: this.$tc('TopdataMapperSW6.settings.copy.message'),
                    });
                }).catch(fallback);
            } else {
                fallback();
            }
        },
    },
});
