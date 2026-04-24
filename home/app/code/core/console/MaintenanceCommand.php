<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MaintenanceCommand extends Command
{
    protected static $defaultName = 'maintenance';
    protected \Phalcon\Di\FactoryDefault $di;

    public function __construct(\Phalcon\Di\FactoryDefault $di)
    {
        parent::__construct();
        $this->di = $di;
    }

    protected function configure()
    {
        $this->setDescription('Toggle maintenance mode.')
            ->setHelp('This command enables or disables maintenance mode.')
            ->addOption('enable', null, null, 'Enable maintenance mode.')
            ->addOption('disable', null, null, 'Disable maintenance mode.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $enable = $input->getOption('enable');
        $disable = $input->getOption('disable');

        if ($enable && $disable) {
            $output->writeln('<error>Error: Please provide only one of --enable or --disable.</error>');
            return Command::FAILURE;
        }

        $maintenanceFile = BP . '/var/maintenance.lock';

        if ($enable) {
            if (!file_exists($maintenanceFile)) {
                touch($maintenanceFile);
                $output->writeln('<fg=green>Maintenance mode is now enabled. ✅</>');
            } else {
                $output->writeln('<fg=yellow>Maintenance mode is already enabled. ❗</>');
            }
        } elseif ($disable) {
            if (file_exists($maintenanceFile)) {
                unlink($maintenanceFile);
                $output->writeln('<fg=green>Maintenance mode is now disabled. ✅</>');
            } else {
                $output->writeln('<fg=yellow>Maintenance mode is already disabled. ❗</>');
            }
        } else {
            $output->writeln('<error>Error: Please provide either --enable or --disable.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
