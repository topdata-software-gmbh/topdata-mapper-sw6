<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;
use Topdata\TopdataMapperSW6\Service\Dsl\DslParseException;
use Topdata\TopdataMapperSW6\Service\Dsl\DslParser;
use Topdata\TopdataMapperSW6\Service\Dsl\DslSerializer;

/**
 * Loads / persists the matching DSL strategy (config field
 * `TopdataMapperSW6.config.matchingStrategy`) and exposes the presets.
 *
 * The DSL string is the single source of truth; presets are label → DSL-string
 * constants (no separate state). The import backstop re-validates the stored
 * strategy per run and fails loudly (getConfiguredStrategy()).
 *
 * 08/2026 created
 */
class DslStrategyService
{
    public const string CONFIG_KEY = 'TopdataMapperSW6.config.matchingStrategy';

    public const string DEFAULT_DSL = 'product.ean:ean | product.manufacturer_number:mpn | product.manufacturer_number:pcd | product.product_number:articleNumbers';

    /** Brand-scoped MPN: `&` binds tighter than `|`; `( )` groups are allowed but not needed here. */
    public const string BRAND_SCOPED_MPN_DSL = 'product.ean:ean | product.manufacturer:topdataBrandIds & product.manufacturer_number:mpn | product.product_number:articleNumbers';

    public const string ARTICLE_NUMBERS_ONLY_DSL = 'product.product_number:articleNumbers';

    public const string EAN_ONLY_DSL = 'product.ean:ean';

    /**
     * @var array<string, array{label: string, dsl: ?string}>|null
     */
    private static ?array $presets = null;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly DslParser            $dslParser,
        private readonly DslSerializer        $dslSerializer,
    ) {
    }

    /**
     * The currently configured DSL string (falls back to the default).
     */
    public function getConfiguredDsl(): string
    {
        $dsl = $this->systemConfigService->getString(self::CONFIG_KEY);

        return $dsl !== '' ? $dsl : self::DEFAULT_DSL;
    }

    /**
     * The configured strategy as compiled AST. Throws DslParseException when
     * the stored value is invalid — the import fails loudly on configs written
     * around the settings-page gate (CLI config:set, direct DB edit, ...).
     *
     * @throws DslParseException
     */
    public function getConfiguredStrategy(): DslOrExpr
    {
        return $this->dslParser->parse($this->getConfiguredDsl());
    }

    /**
     * Validates and persists a strategy. Nothing is written on violation.
     *
     * @throws DslParseException
     */
    public function save(string $dsl): void
    {
        $ast = $this->dslParser->parse($dsl);
        $this->systemConfigService->set(self::CONFIG_KEY, $this->dslSerializer->toString($ast));
    }

    /**
     * Presets as served to the settings page: [{key, label, dsl}] with
     * `dsl: null` for the Custom preset (highlighted when the current DSL
     * matches no canonical preset string).
     *
     * @return list<array{key: string, label: string, dsl: ?string}>
     */
    public function getPresets(): array
    {
        if (self::$presets === null) {
            self::$presets = [
                ['key' => 'default',                'label' => 'Default',                'dsl' => self::DEFAULT_DSL],
                ['key' => 'brand-scoped-mpn',       'label' => 'Brand-scoped MPN',       'dsl' => self::BRAND_SCOPED_MPN_DSL],
                ['key' => 'article-numbers-only',   'label' => 'Article numbers only',   'dsl' => self::ARTICLE_NUMBERS_ONLY_DSL],
                ['key' => 'ean-only',               'label' => 'EAN only',               'dsl' => self::EAN_ONLY_DSL],
                ['key' => 'custom',                 'label' => 'Custom',                 'dsl' => null],
            ];
        }

        return self::$presets;
    }
}