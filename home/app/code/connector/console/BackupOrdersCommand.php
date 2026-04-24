<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Connector\Components\Backup\Order\Archiver;

class BackupOrdersCommand extends Command
{

    protected static $defaultName = "backup-orders";

    protected static $defaultDescription = "Backup Orders";
    protected $di;


    public function __construct($di)
    {
        parent::__construct();
        $this->di = $di;
    }

    protected function configure()
    {
        $this
            ->addOption('age', 'a', InputOption::VALUE_REQUIRED, 'Age month wise', null)
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Order status', null)
            ->setDescription(self::$defaultDescription)
            ->setHelp('This command allows you to backup orders. Options --age and --status are required.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $age = $input->getOption('age');
        $status = $input->getOption('status');

        $this->processCommand($age, $status);

        $output->writeln('Orders backup started successfully.');
    }

    private function processCommand(string $age, $status): void {
        $currentDate = new \DateTime('now');
        $toDate = $currentDate->sub(new \DateInterval('P' . $age . 'M'))->format('Y-m-d');
        $this->di->getObjectManager()->get(Archiver::class)->start($status, $toDate, date('Y-m-d'));
    }

}