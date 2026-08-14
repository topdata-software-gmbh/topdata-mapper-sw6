import template from './topdata-mapper-settings.html.twig';
import './topdata-mapper-settings.scss';

const { Component, Mixin } = Shopware;

/**
 * Card B — matching strategy editor.
 *
 * The DSL string is the single source of truth; the visual builder and the
 * textarea are live-synced views over it ("last-edited side wins"). The PHP
 * parser is authoritative — textarea edits are re-validated debounced via
 * validate-strategy and the builder re-renders from the PHP-parsed AST.
 *
 * 08/2026 created
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
        providers: [],
        credentialsConfigured: false,

        // ---- builder model ----
        groups: [],
        propertyGroups: [],
        customFieldNames: [],

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
         * Shop-field kinds for the leaf selects (property/customField are
         * parametrized via the group/field variant select).
         */
        shopFieldKinds() {
            return Object.keys(this.allowedPairs);
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
                    this.providers = response.data.providers;
                    this.credentialsConfigured = response.data.credentialsConfigured;
                    this.loadPropertyGroups();
                    this.loadCustomFields();
                    return this.validateDsl();
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadPropertyGroups() {
            const repository = Shopware.Service('repositoryFactory').create('property_group');
            return repository.search(new Shopware.Data.Criteria()).then((result) => {
                this.propertyGroups = result.map((group) => ({ value: group.name, label: group.name }));
            });
        },

        loadCustomFields() {
            const repository = Shopware.Service('repositoryFactory').create('custom_field_set');
            const criteria = new Shopware.Data.Criteria();
            criteria.addFilter(Shopware.Data.Criteria.equals('active', true));
            return repository.search(criteria).then((result) => {
                this.customFieldNames = [];
                result.forEach((set) => {
                    (set.customFields || []).forEach((field) => {
                        if (field.name) {
                            this.customFieldNames.push({ value: field.name, label: field.label || field.name });
                        }
                    });
                });
            });
        },

        // ------------------------------------------------------------- labels
        openDslHelp() {
            this.showDslHelp = true;
        },

        closeDslHelp() {
            this.showDslHelp = false;
        },

        shopFieldLabel(kind) {
            const labels = {
                'product.ean': this.$tc('TopdataMapperSW6.settings.shopField.product.ean'),
                'product.manufacturer_number': this.$tc('TopdataMapperSW6.settings.shopField.product.manufacturer_number'),
                'product.manufacturer': this.$tc('TopdataMapperSW6.settings.shopField.product.manufacturer'),
                'product.product_number': this.$tc('TopdataMapperSW6.settings.shopField.product.product_number'),
                'property': this.$tc('TopdataMapperSW6.settings.shopField.property'),
                'customField': this.$tc('TopdataMapperSW6.settings.shopField.customField'),
            };

            return labels[kind] || kind;
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

        // ------------------------------------------------------ builder model
        /**
         * Converts the PHP-parsed AST into the builder model. Leaf shape:
         * {kind, dimension, group, provider} where group = property group name
         * / custom field name and provider = provider id (articleNumbers scope).
         */
        builderFromAst(ast) {
            return (ast.groups || []).map((group) => ({
                leaves: (group.leaves || []).map((leaf) => {
                    const kind = leaf.shopField.startsWith('property.')
                        ? 'property'
                        : leaf.shopField.startsWith('customField.')
                            ? 'customField'
                            : leaf.shopField;

                    let groupValue = null;
                    if (kind === 'property' || kind === 'customField') {
                        groupValue = leaf.shopField.slice(leaf.shopField.indexOf('.') + 1);
                    }

                    return {
                        kind,
                        dimension: leaf.dimension,
                        group: groupValue,
                        provider: leaf.dimensionVariant,
                    };
                }),
            }));
        },

        /**
         * Serializes the builder model to a DSL string. Leaves whose required
         * variant selects are not yet chosen are "incomplete" — they contribute
         * nothing, so the builder can never generate invalid DSL.
         */
        dslFromBuilder() {
            const orParts = [];
            for (const group of this.groups) {
                const andParts = [];
                for (const leaf of group.leaves) {
                    if (!this._leafComplete(leaf)) {
                        continue;
                    }
                    let shopField = leaf.kind;
                    if (leaf.group !== null) {
                        shopField += '.' + leaf.group;
                    }
                    let dimension = leaf.dimension;
                    if (leaf.dimension === 'articleNumbers' && leaf.provider !== null) {
                        dimension += '.' + leaf.provider;
                    }
                    andParts.push(shopField + ':' + dimension);
                }
                if (andParts.length > 0) {
                    orParts.push(andParts.join(' & '));
                }
            }

            return orParts.join(' | ');
        },

        _leafComplete(leaf) {
            if ((leaf.kind === 'property' || leaf.kind === 'customField') && !leaf.group) {
                return false;
            }

            return true;
        },

        _dimensionOptions(kind) {
            return this.allowedPairs[kind] || [];
        },

        // ------------------------------------------------------- builder edits
        onLeafShopFieldChange(leaf) {
            leaf.dimension = this._dimensionOptions(leaf.kind)[0] || null;
            leaf.group = null;
            leaf.provider = null;
            this.applyBuilder();
        },

        onLeafDimensionChange(leaf) {
            if (leaf.dimension !== 'articleNumbers') {
                leaf.provider = null;
            }
            this.applyBuilder();
        },

        onLeafVariantChange() {
            this.applyBuilder();
        },

        addLeaf(group) {
            group.leaves.push({
                kind: this.shopFieldKinds[0],
                dimension: this._dimensionOptions(this.shopFieldKinds[0])[0] || null,
                group: null,
                provider: null,
            });
        },

        removeLeaf(group, index) {
            group.leaves.splice(index, 1);
            this.applyBuilder();
        },

        addGroup() {
            this.groups.push({
                leaves: [{
                    kind: this.shopFieldKinds[0],
                    dimension: this._dimensionOptions(this.shopFieldKinds[0])[0] || null,
                    group: null,
                    provider: null,
                }],
            });
            this.applyBuilder();
        },

        removeGroup(index) {
            this.groups.splice(index, 1);
            this.applyBuilder();
        },

        /**
         * Builder edit → replace the DSL string (fully replaces the textarea).
         */
        applyBuilder() {
            this.dsl = this.dslFromBuilder();
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
                    if (response.data.valid) {
                        this.groups = this.builderFromAst(response.data.ast);
                    }
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
