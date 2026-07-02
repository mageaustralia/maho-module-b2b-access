<?php

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot import of Amasty Customer Group Catalog rules into B2B Access rules.
 * Delegates to b2baccess/import_groupcat so the same logic is testable. Safe to
 * re-run: already-imported rules (named "Groupcat: ...") are skipped.
 */
#[AsCommand(
    name: 'b2baccess:import-groupcat',
    description: 'Import Amasty Customer Group Catalog rules into B2B Access rules',
)]
class B2bAccessImportGroupcat extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be imported without writing anything');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $dryRun = (bool) $input->getOption('dry-run');

        /** @var \MageAustralia_B2bAccess_Model_Import_Groupcat $importer */
        $importer = Mage::getModel('b2baccess/import_groupcat');
        $result = $importer->import($dryRun);

        if (!$result['available']) {
            $output->writeln('<comment>No Amasty Groupcat tables found (am_groupcat_rules). Nothing to import.</comment>');
            return Command::SUCCESS;
        }

        foreach ($result['rules'] as $name) {
            $output->writeln(($dryRun ? '  [would import] ' : '  [imported] ') . $name);
        }

        $output->writeln(sprintf(
            '<info>%s: %d rule(s), %d product link(s); %d skipped (already present).</info>',
            $dryRun ? 'Dry run' : 'Done',
            $result['imported'],
            $result['products'],
            $result['skipped'],
        ));

        if (!$dryRun && $result['imported'] > 0) {
            $output->writeln('<comment>Set B2B Access Mode = Rules and reindex Meilisearch to apply.</comment>');
        }

        return Command::SUCCESS;
    }
}
