<?php

declare(strict_types=1);

namespace Topdata\TopdataMapperSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Helper\CliStyle;
use Topdata\TopdataFoundationSW6\Service\CliApiCredentialPrompter;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
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

        match ($mapping) {
            'product' => $this->mappingBuildService->buildProductMappings(),
            'brand'   => $this->mappingBuildService->buildBrandMappings(),
            'all'     => $this->_buildAll(),
            default   => throw new \InvalidArgumentException("Unknown --mapping value '{$mapping}' (product|brand|all)"),
        };

        return self::SUCCESS;
    }

    private function _buildAll(): void
    {
        $this->mappingBuildService->buildProductMappings();
        $this->mappingBuildService->buildBrandMappings();
    }
}
