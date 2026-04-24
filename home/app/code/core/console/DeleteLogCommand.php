<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Check status for user || shop || app
 * argument : user_id, shop_id, app_id
 */
class DeleteLogCommand extends Command
{
    protected static $defaultName = "clear-log";
    protected static $defaultDescription = "Clear old webhook log files.";
    private \Phalcon\Di\FactoryDefault $di;

    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct(\Phalcon\Di\FactoryDefault $di)
    {
        parent::__construct();
        $this->di = $di;
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Clear old webhook log files <comment>e.g: clear-log');
        $this->addArgument(
            "log_type",
            InputArgument::OPTIONAL,
            "[all : for removing all logs]",
            false
        );
        $this->addOption("dir", "d", InputOption::VALUE_REQUIRED, "[dir name]");
        $this->addOption("file", "f", InputOption::VALUE_REQUIRED, "[file name]");

    }
    /**
     * The main logic to execute when the command is run
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $logType = $input->getArgument("log_type");
        
        $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Deleting log files... </>");
        if ($logType == 'all') {
            $io->confirm('Are you sure you want to delete complete log?');
            $fullPath = BP."/var/log/";
            $response = $this->removeDirectory($fullPath);
            if ($response) $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=green> " . "All logs deleted successfully." . "</>");
            return 0;
        } 
        $dir = $input->getOption('dir');
        $file = $input->getOption('file');
        if ($dir){
            $path = BP."/var/log/" . $dir;
        }

        if ($file) {
            $path = BP."/var/log/" . $file.".log";
        }
        if (isset($path) && is_dir($path)) {
            $response = $this->removeDirectory($path);
            if ($response) $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=green> " . "Log dir deleted successfully." . "</>");
        } elseif (isset($path) && is_file($path)) {
            unlink($path);
            $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=green> " . "Log files deleted successfully." . "</>");
        } else {
            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> " . "Given dir or file doesn't exists!" . "</>");
        }
    
        return 0;
    }


    function removeDirectory($path)
    {
        $files = array_diff(scandir($path), array('.', '..'));
        if (empty($files)) {
            rmdir($path);
        }
        $isEmpty = true;
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            if (is_dir($fullPath)) {
                if ($this->removeDirectory($fullPath)) {
                    if(is_dir($fullPath))
                        rmdir($fullPath);
                } else {
                    $isEmpty = false;
                }
            } else {
                unlink($fullPath);
            }
        }
        return $isEmpty;
    }
}
