<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Console\Command;

use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    private const OPTION_STATUS = 'status';
    private const OPTION_LIMIT = 'limit';

    public function __construct(private readonly DispatchResource $dispatchResource, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('goodahead:ordersync:status')
            ->setDescription('Shows finance deliveries and their state')
            ->addOption(self::OPTION_STATUS, 's', InputOption::VALUE_REQUIRED, 'pending, in_progress, succeeded or failed')
            ->addOption(self::OPTION_LIMIT, 'l', InputOption::VALUE_REQUIRED, 'How many rows to show', '20');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->dispatchResource->countByStatus();

        if ($counts === []) {
            $output->writeln('<info>No deliveries have been registered.</info>');

            return Command::SUCCESS;
        }

        $summary = [];
        foreach ($counts as $status => $total) {
            $summary[] = sprintf('%s: %d', $status, $total);
        }
        $output->writeln('<info>' . implode('   ', $summary) . '</info>');

        if (($counts['failed'] ?? 0) > 0) {
            $output->writeln('<comment>Terminally failed deliveries need an operator. '
                . 'Fix the cause, then: bin/magento goodahead:ordersync:retry</comment>');
        }

        $status = $input->getOption(self::OPTION_STATUS);
        $rows = $this->dispatchResource->listRows(
            $status === null ? null : (string)$status,
            max(1, (int)$input->getOption(self::OPTION_LIMIT))
        );

        $table = new Table($output);
        $table->setHeaders(['Order', 'Event', 'Status', 'Tries', 'HTTP', 'Next attempt', 'Last error']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['increment_id'],
                $row['event_type'],
                $row['status'],
                $row['attempts'],
                $row['last_status_code'] ?? '-',
                $row['next_attempt_at'] ?? '-',
                mb_substr((string)($row['last_error'] ?? ''), 0, 48),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
