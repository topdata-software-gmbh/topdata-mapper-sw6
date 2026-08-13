<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataFoundationSW6\Service\CliApiCredentialPrompter;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMapperSW6\Service\MappingBuildStats;
use Topdata\TopdataMapperSW6\Service\TdmpMappingBuildService;
use Topdata\TopdataMapperSW6\Service\TopdataMapperWebserviceV2Client;

#[AsCommand(name: 'topdata:mapper:import', description: 'Build the Topdata↔SW6 mapping tables (tdmp_product, tdmp_brand)')]
class Command_TdmpImport extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TdmpMappingBuildService        $mappingBuildService,
        private readonly TopdataMapperWebserviceV2Client $mapperClient,
        private readonly CliApiCredentialPrompter       $credentialPrompter,
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
     * @return MappingBuildStats[]
     */
    private function _buildAll(): array
    {
        return [
            $this->mappingBuildService->buildProductMappings(),
            $this->mappingBuildService->buildBrandMappings(),
        ];
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
                $this->_padCell('tdmp_' . $stat->entity),
                $this->_padCell(number_format($stat->pages)),
                $this->_padCell(number_format($stat->apiRows)),
                $this->_padCell(number_format($stat->matched)),
                $this->_padCell(number_format($stat->unmatched)),
                $this->_padCell(sprintf('%.1f s', $stat->duration)),
            ];
        }

        $rows[] = new TableSeparator();

        $totals = $this->_sumStats($stats);
        $rows[] = [
            $this->_padCell('Total'),
            $this->_padCell(number_format($totals['pages'])),
            $this->_padCell(number_format($totals['apiRows'])),
            $this->_padCell(number_format($totals['matched'])),
            $this->_padCell(number_format($totals['unmatched'])),
            $this->_padCell(sprintf('%.1f s', $totals['duration'])),
        ];

        $tbl = $this->cliStyle->createTable();
        $tbl->setStyle('box-double');
        $tbl->getStyle()->setPadType(STR_PAD_BOTH);
        $tbl->setHeaders(['Mapping', 'Pages', 'API rows', 'Matched', 'Unmatched', 'Duration']);
        $tbl->setRows($rows);
        $tbl->setHeaderTitle(' Import Summary ');
        $tbl->render();

        $this->cliStyle->newLine();
    }

    /**
     * @param MappingBuildStats[] $stats
     * @return array{pages: int, apiRows: int, matched: int, unmatched: int, duration: float}
     */
    private function _sumStats(array $stats): array
    {
        return [
            'pages'     => array_sum(array_map(fn(MappingBuildStats $s) => $s->pages, $stats)),
            'apiRows'   => array_sum(array_map(fn(MappingBuildStats $s) => $s->apiRows, $stats)),
            'matched'   => array_sum(array_map(fn(MappingBuildStats $s) => $s->matched, $stats)),
            'unmatched' => array_sum(array_map(fn(MappingBuildStats $s) => $s->unmatched, $stats)),
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
