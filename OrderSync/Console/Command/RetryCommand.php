<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Console\Command;

use Goodahead\OrderSync\Model\ResourceModel\Dispatch as DispatchResource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetryCommand extends Command
{
    private const ARGUMENT_ORDERS = 'orders';

    public function __construct(private readonly DispatchResource $dispatchResource, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('goodahead:ordersync:retry')
            ->setDescription('Returns terminally failed finance deliveries to the queue')
            ->addArgument(
                self::ARGUMENT_ORDERS,
                InputArgument::IS_ARRAY,
                'Order increment IDs. Omit to requeue every failed delivery.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $orders */
        $orders = (array)$input->getArgument(self::ARGUMENT_ORDERS);
        $requeued = $this->dispatchResource->requeueFailed($orders);

        if ($requeued === 0) {
            $output->writeln('<info>Nothing to requeue.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Requeued %d deliver%s. The next cron run will attempt them.</info>',
            $requeued,
            $requeued === 1 ? 'y' : 'ies'
        ));

        return Command::SUCCESS;
    }
}
