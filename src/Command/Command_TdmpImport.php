<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataFoundationSW6\Service\CliApiCredentialPrompter;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMapperSW6\Service\Dsl\DslOrExpr;
use Topdata\TopdataMapperSW6\Service\Dsl\DslSerializer;
use Topdata\TopdataMapperSW6\Service\DslStrategyService;
use Topdata\TopdataMapperSW6\Service\MappingBuildStats;
use Topdata\TopdataMapperSW6\Service\ProductMappingMatcher_Dsl;
use Topdata\TopdataMapperSW6\Service\TdmpMappingBuildService;
use Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client;

/**
 * 08/2026 created
 */
#[AsCommand(
    name: 'topdata:mapper:import',
    description: 'Build the Topdata↔SW6 mapping tables (tdmp_product, tdmp_brand)'
)]
class Command_TdmpImport extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TdmpMappingBuildService        $mappingBuildService,
        private readonly TopdataMapperWebserviceV2Client $mapperClient,
        private readonly CliApiCredentialPrompter       $credentialPrompter,
        private readonly DslStrategyService              $strategyService,
        private readonly DslSerializer                   $dslSerializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mapping',
            'm',
            InputOption::VALUE_REQUIRED,
            'Which mapping to build: product | brand | all',
            'all'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle = new CliStyle($input, $output);
        CliLogger::setCliStyle($this->cliStyle);
        CliLogger::section('Topdata Mapper: import mapping data');

        if (!$this->credentialPrompter->ensureValidApiConfig($input, $output, $this->mapperClient, 'TopdataMapperSW6')) {
            CliLogger::error('API credentials are not configured. Please set API Base URL and API Key (sk-...) in the plugin configuration.');

            return self::FAILURE;
        }

        $mapping = strtolower((string)$input->getOption('mapping'));

        if ($mapping !== 'brand') {
            $this->_printStrategyTable($this->strategyService->getConfiguredStrategy());
        }

        $stats = match ($mapping) {
            'product' => [$this->mappingBuildService->buildProductMappings()],
            'brand'   => [$this->mappingBuildService->buildBrandMappings()],
            'all'     => $this->_buildAll(),
            default   => throw new \InvalidArgumentException("Unknown --mapping value '{$mapping}' (product|brand|all)"),
        };

        $this->_printSummary($stats);

        return self::SUCCESS;
    }

    /**
     * Builds product + brand mappings. The brand build runs FIRST when the
     * strategy references topdataBrandIds — the product build's reverse map
     * (shop manufacturer → Topdata brand id via tdmp_brand) needs fresh brand
     * rows to match anything.
     *
     * @return MappingBuildStats[]
     */
    private function _buildAll(): array
    {
        $strategy = $this->strategyService->getConfiguredStrategy();

        if (ProductMappingMatcher_Dsl::referencesTopdataBrandIds($strategy)) {
            return [
                $this->mappingBuildService->buildBrandMappings(),
                $this->mappingBuildService->buildProductMappings(),
            ];
        }

        return [
            $this->mappingBuildService->buildProductMappings(),
            $this->mappingBuildService->buildBrandMappings(),
        ];
    }

    /**
     * Prints the configured matching strategy as a flat table of its leaves
     * (parens, `&` and `|` are implied by the grammar and not rendered) and
     * the DSL definition below it.
     */
    private function _printStrategyTable(DslOrExpr $strategy): void
    {
        $rows = [];
        foreach (ProductMappingMatcher_Dsl::collectLeaves($strategy) as $leaf) {
            $rows[] = [
                $this->_cell($leaf->shopField),
                $this->_cell($leaf->dimensionVariant !== null ? $leaf->dimension . '.' . $leaf->dimensionVariant : $leaf->dimension),
            ];
        }

        $tbl = $this->cliStyle->createTable();
        $tbl->setStyle('box');
        $tbl->getStyle()
            ->setPadType(STR_PAD_BOTH)
            ->setHeaderTitleFormat('<fg=black;bg=cyan;options=bold> %s </>');
        $tbl->setHeaders([
            $this->_cell('Shop field', ['fg' => 'cyan', 'options' => 'bold']),
            $this->_cell('API dimension', ['fg' => 'cyan', 'options' => 'bold']),
        ]);
        $tbl->setRows($rows);
        $tbl->setHeaderTitle('Matching strategy');
        $tbl->render();

        $this->cliStyle->writeln('  DSL: <fg=cyan>' . $this->dslSerializer->toString($strategy) . '</>');
        $this->cliStyle->newLine();
    }

    /**
     * Prints the pretty summary table of the import run.
     *
     * @param MappingBuildStats[] $stats
     */
    private function _printSummary(array $stats): void
    {
        $rows = [];
        foreach ($stats as $stat) {
            $rows[] = [
                $this->_cell('tdmp_' . $stat->entity),
                $this->_cell(number_format($stat->pages)),
                $this->_cell(number_format($stat->apiRows)),
                $this->_cell(number_format($stat->matched), $stat->matched > 0 ? ['fg' => 'green', 'options' => 'bold'] : null),
                $this->_cell(number_format($stat->unmatched), $stat->unmatched > 0 ? ['fg' => 'yellow', 'options' => 'bold'] : null),
                $this->_cell(number_format($stat->sw6Total)),
                $this->_cell(number_format($stat->sw6Total - $stat->matched), $stat->sw6Total - $stat->matched > 0 ? ['fg' => 'yellow', 'options' => 'bold'] : null),
                $this->_cell(number_format($stat->conflicts), $stat->conflicts > 0 ? ['fg' => 'yellow', 'options' => 'bold'] : null),
                $this->_cell(sprintf('%.1f s', $stat->duration)),
            ];
        }

        $rows[] = new TableSeparator();

        $totals = $this->_sumStats($stats);
        $rows[] = [
            $this->_cell('Total', ['fg' => 'white', 'options' => 'bold']),
            $this->_cell(number_format($totals['pages']), ['options' => 'bold']),
            $this->_cell(number_format($totals['apiRows']), ['options' => 'bold']),
            $this->_cell(number_format($totals['matched']), ['options' => 'bold']),
            $this->_cell(number_format($totals['unmatched']), ['options' => 'bold']),
            $this->_cell(number_format($totals['sw6Total']), ['options' => 'bold']),
            $this->_cell(number_format($totals['sw6Total'] - $totals['matched']), ['options' => 'bold']),
            $this->_cell(number_format($totals['conflicts']), ['options' => 'bold']),
            $this->_cell(sprintf('%.1f s', $totals['duration']), ['options' => 'bold']),
        ];

        $headers = [];
        foreach (['Mapping', 'Pages', 'API rows', 'Matched', 'Unmatched TD', 'SW6 Total', 'SW6 unmatched', 'Conflicts', 'Duration'] as $header) {
            $headers[] = $this->_cell($header, ['fg' => 'cyan', 'options' => 'bold']);
        }

        $tbl = $this->cliStyle->createTable();
        $tbl->setStyle('box');
        $tbl->getStyle()
            ->setPadType(STR_PAD_BOTH)
            ->setHeaderTitleFormat('<fg=black;bg=cyan;options=bold> %s </>');
        $tbl->setHeaders([$headers]);
        $tbl->setRows($rows);
        $tbl->setHeaderTitle('Import Summary');
        $tbl->render();

        $this->cliStyle->newLine();
    }

    /**
     * Creates a centered, padded table cell with optional styling.
     *
     * @param array{fg?: string, bg?: string, options?: string} $styleOptions
     */
    private function _cell(string $value, ?array $styleOptions = null): TableCell
    {
        $style = new TableCellStyle(array_merge(['align' => 'center'], $styleOptions ?? []));

        return new TableCell($this->_padCell($value), ['style' => $style]);
    }

    /**
     * @param MappingBuildStats[] $stats
     * @return array{pages: int, apiRows: int, matched: int, unmatched: int, sw6Total: int, conflicts: int, duration: float}
     */
    private function _sumStats(array $stats): array
    {
        return [
            'pages'     => array_sum(array_map(fn(MappingBuildStats $s) => $s->pages, $stats)),
            'apiRows'   => array_sum(array_map(fn(MappingBuildStats $s) => $s->apiRows, $stats)),
            'matched'   => array_sum(array_map(fn(MappingBuildStats $s) => $s->matched, $stats)),
            'unmatched' => array_sum(array_map(fn(MappingBuildStats $s) => $s->unmatched, $stats)),
            'sw6Total'  => array_sum(array_map(fn(MappingBuildStats $s) => $s->sw6Total, $stats)),
            'conflicts' => array_sum(array_map(fn(MappingBuildStats $s) => $s->conflicts, $stats)),
            'duration'  => array_sum(array_map(fn(MappingBuildStats $s) => $s->duration, $stats)),
        ];
    }

    /**
     * Adds extra horizontal padding around a table cell.
     */
    private function _padCell(string $cell): string
    {
        return ' ' . $cell . ' ';
    }
}
