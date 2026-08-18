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
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMapperSW6\Service\Db\TdmpProductCountService;

/**
 * Counts Shopware products by identifier dimension (ean / mpn / article
 * number) and optionally per custom-field name — the debugging companion of
 * `topdata:mapper:import` (e.g. to explain a run with zero matches: are the
 * identifiers even present in the shop?).
 *
 * DB-side only, no API call. Scope: live-version products; variants included
 * unless --parents-only is given. Counts exclude junk placeholder values
 * (-, n/a, no-digit EAN) by default; --show-placeholders adds the excluded
 * products as yellow sub-rows.
 *
 * 08/2026 created
 */
#[AsCommand(
    name: 'topdata:mapper:count',
    description: 'Count shop products with EAN / MPN / article number (and optionally per custom field)'
)]
class Command_TdmpCount extends AbstractTopdataCommand
{
    public function __construct(
        private readonly TdmpProductCountService $countService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'also-customfields',
            'c',
            InputOption::VALUE_NONE,
            'Also count products per custom-field name'
        );
        $this->addOption(
            'parents-only',
            'p',
            InputOption::VALUE_NONE,
            'Count only main products (parent_id IS NULL), excluding variants'
        );
        $this->addOption(
            'show-placeholders',
            null,
            InputOption::VALUE_NONE,
            "Also show placeholder-value counts — products whose identifier is only junk ('-', 'n/a', no-digit EAN); excluded from the main counts by default"
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parentsOnly = (bool)$input->getOption('parents-only');
        $counts      = $this->countService->countIdentifiers($parentsOnly);

        CliLogger::info(sprintf(
            'Counting %s (live version, %s).',
            $parentsOnly ? 'main products only' : 'all products incl. variants',
            number_format($counts['total'])
        ));

        $this->_printIdentifierTable($counts, (bool)$input->getOption('show-placeholders'));

        if ($input->getOption('also-customfields')) {
            if ($this->countService->hasCustomFieldsColumn()) {
                $this->_printCustomFieldTable($this->countService->countCustomFields($parentsOnly), $counts['total']);
            } else {
                CliLogger::warning('product.custom_fields column not present in this shop — custom-field counts skipped.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array{total: int, ean: int, mpn: int, articleNumber: int, any: int, placeholderEan: int, placeholderMpn: int, placeholderArticleNumber: int} $counts
     */
    private function _printIdentifierTable(array $counts, bool $showPlaceholders): void
    {
        $rows = [
            ['EAN', $counts['ean'], $counts['placeholderEan'], 'product.ean', 'placeholder-only (no digit)'],
            ['MPN', $counts['mpn'], $counts['placeholderMpn'], 'product.manufacturer_number', 'placeholder-only (-, n/a)'],
            ['Article number', $counts['articleNumber'], $counts['placeholderArticleNumber'], 'product.product_number', 'placeholder-only (-, n/a)'],
            ['Any identifier', $counts['any'], 0, '', ''],
        ];

        $tableRows = [];
        foreach ($rows as [$label, $count, $placeholder, $source, $placeholderLabel]) {
            $tableRows[] = [
                $this->_cell($label),
                $this->_cell(number_format($count), $this->_countStyle($count)),
                $this->_cell($this->_percent($count, $counts['total'])),
                $this->_cell($source),
            ];
            if ($showPlaceholders && $placeholder > 0) {
                $tableRows[] = [
                    $this->_cell('  ' . $placeholderLabel . ' — excluded above', ['fg' => 'yellow']),
                    $this->_cell(number_format($placeholder), ['fg' => 'yellow', 'options' => 'bold']),
                    $this->_cell($this->_percent($placeholder, $counts['total'])),
                    $this->_cell(''),
                ];
            }
        }
        $tableRows[] = new TableSeparator();
        $tableRows[] = [
            $this->_cell('Total products', ['fg' => 'white', 'options' => 'bold']),
            $this->_cell(number_format($counts['total']), ['options' => 'bold']),
            $this->_cell('100.0%', ['options' => 'bold']),
            $this->_cell(''),
        ];

        $this->_renderTable('Identifier counts', ['Identifier', 'Products', '% of total', 'Source column'], $tableRows);
    }

    /**
     * @param array<string, int> $fieldCounts custom field name → product count
     */
    private function _printCustomFieldTable(array $fieldCounts, int $total): void
    {
        $rows = [];
        foreach ($fieldCounts as $name => $count) {
            $rows[] = [
                $this->_cell($name),
                $this->_cell(number_format($count), $this->_countStyle($count)),
                $this->_cell($this->_percent($count, $total)),
            ];
        }
        if ($rows === []) {
            $rows[] = [
                $this->_cell('— no custom fields set —', ['fg' => 'yellow']),
                $this->_cell(''),
                $this->_cell(''),
            ];
        }

        $this->_renderTable('Custom field counts', ['Field', 'Products', '% of total'], $rows);
    }

    /**
     * @param array<int, array{0: TableCell, 1?: TableCell, 2?: TableCell, 3?: TableCell}|TableSeparator> $rows
     * @param string[] $headers
     */
    private function _renderTable(string $title, array $headers, array $rows): void
    {
        $headerCells = [];
        foreach ($headers as $header) {
            $headerCells[] = $this->_cell($header, ['fg' => 'cyan', 'options' => 'bold']);
        }

        $tbl = $this->cliStyle->createTable();
        $tbl->setStyle('box');
        $tbl->getStyle()
            ->setPadType(STR_PAD_BOTH)
            ->setHeaderTitleFormat('<fg=black;bg=cyan;options=bold> %s </>');
        $tbl->setHeaders([$headerCells]);
        $tbl->setRows($rows);
        $tbl->setHeaderTitle($title);
        $tbl->render();

        $this->cliStyle->newLine();
    }

    /**
     * @return array{fg?: string, options?: string}
     */
    private function _countStyle(int $count): array
    {
        return $count > 0 ? ['fg' => 'green', 'options' => 'bold'] : ['fg' => 'yellow', 'options' => 'bold'];
    }

    private function _percent(int $count, int $total): string
    {
        return $total > 0 ? sprintf('%.1f%%', $count / $total * 100) : '—';
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
     * Adds extra horizontal padding around a table cell.
     */
    private function _padCell(string $cell): string
    {
        return ' ' . $cell . ' ';
    }
}