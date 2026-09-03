<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Console\Command;

use Goodahead\OrderSync\Cron\DispatchSweeper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers paid orders that never reached the ledger, over a window of the operator's
 * choosing.
 *
 * Cron already does this every minute over the configured window. This exists for the case
 * that window cannot cover: something was broken for longer than it, and the orders from that
 * period have to be caught up deliberately rather than by widening a setting and waiting.
 */
class ReconcileCommand extends Command
{
    private const OPTION_DAYS = 'days';

    public function __construct(
        private readonly DispatchSweeper $sweeper,
        private readonly DateTime $dateTime,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('goodahead:ordersync:reconcile')
            ->setDescription('Registers paid orders that are missing from the finance delivery ledger')
            ->addOption(
                self::OPTION_DAYS,
                'd',
                InputOption::VALUE_REQUIRED,
                'How many days back to look. Defaults to the configured reconciliation window.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption(self::OPTION_DAYS);
        $registered = $this->sweeper->registerOrdersTheObserverMissed(
            $this->dateTime->gmtTimestamp(),
            $days === null ? null : max(1, (int)$days)
        );

        $output->writeln($registered === 0
            ? '<info>Every paid order in that window is already in the ledger.</info>'
            : sprintf('<info>Registered %d order(s). Delivery follows on the next cron run.</info>', $registered));

        return Command::SUCCESS;
    }
}
