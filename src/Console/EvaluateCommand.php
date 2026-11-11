<?php

namespace ErnestDefoe\TrustLevels\Console;

use ErnestDefoe\TrustLevels\Evaluator;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum trust-levels:check [--all]
 *
 * Hourly (scheduled): members seen since the last run, and anyone not placed
 * yet. Nightly with --all: every Regular too (their 100-day window moves
 * whether they visit or not), then old days are folded and pruned.
 */
class EvaluateCommand extends AbstractCommand
{
    public function __construct(private Evaluator $evaluator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('trust-levels:check')
            ->setDescription('Move members to the trust level their activity has earned.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also check every Regular, and prune old daily rows.');
    }

    protected function fire(): int
    {
        $checked = $this->evaluator->run((bool) $this->input->getOption('all'));
        $this->info("Checked $checked members.");

        return 0;
    }
}
