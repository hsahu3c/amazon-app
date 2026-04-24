<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

class UpdateSqsMaxMessageSizeCommand extends Command
{
    /**
     * To run in dry-run mode. Only list queues and report what would be updated; no changes are made.
     * php app/cli amazon:sqs:update-max-message-size --dry-run  
     * 
     * To run in actual mode.
     * php app/cli amazon:sqs:update-max-message-size
     */
    protected static $defaultName = 'amazon:sqs:update-max-message-size';

    protected static $defaultDescription = 'Update MaximumMessageSize of all SQS queues to 1024 KB if currently less';

    private const MIN_SIZE_KB = 1024;
    private const TARGET_SIZE_BYTES = 1048576; // 1024 KB = 1 MiB

    /** @var \Phalcon\Di\FactoryDefault */
    private $di;

    public function __construct(\Phalcon\Di\FactoryDefault $di)
    {
        parent::__construct();
        $this->di = $di;
    }

    protected function configure()
    {
        $this->setHelp('Update MaximumMessageSize of all SQS queues to 1024 KB (1 MiB) when current value is less. Uses AWS config from app/etc/aws.php.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list queues and what would be updated; do not apply changes');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln('<options=bold;fg=blue>Update SQS MaximumMessageSize to ' . self::MIN_SIZE_KB . ' KB</>');
        if ($dryRun) {
            $output->writeln('<comment>Dry run – no changes will be applied.</comment>');
        }
        $output->writeln('');

        $client = $this->getSqsClient();
        if (!$client) {
            $output->writeln('<error>AWS config not available. Ensure app/etc/aws.php exists and returns region and credentials.</error>');
            return 1;
        }

        $queueUrls = $this->listAllQueueUrls($client);
        $output->writeln('Found ' . count($queueUrls) . ' queue(s).');

        if (empty($queueUrls)) {
            $output->writeln('<info>No queues to process.</info>');
            return 0;
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($queueUrls as $queueUrl) {
            $queueName = basename(parse_url($queueUrl, PHP_URL_PATH));
            try {
                $attrs = $client->getQueueAttributes([
                    'QueueUrl'       => $queueUrl,
                    'AttributeNames' => ['MaximumMessageSize'],
                ]);
                $currentBytes = (int) ($attrs->get('Attributes')['MaximumMessageSize'] ?? 0);
                $currentKb = (int) round($currentBytes / 1024);

                if ($currentBytes < self::TARGET_SIZE_BYTES) {
                    if ($dryRun) {
                        $output->writeln("  <comment>[DRY-RUN] Would update:</comment> {$queueName} (current: {$currentKb} KB → " . self::MIN_SIZE_KB . " KB)");
                    } else {
                        $client->setQueueAttributes([
                            'QueueUrl'   => $queueUrl,
                            'Attributes' => [
                                'MaximumMessageSize' => (string) self::TARGET_SIZE_BYTES,
                            ],
                        ]);
                        $output->writeln("  <info>Updated:</info> {$queueName} ({$currentKb} KB → " . self::MIN_SIZE_KB . " KB)");
                    }
                    $updated++;
                } else {
                    $output->writeln("  <comment>Skip:</comment> {$queueName} (already {$currentKb} KB ≥ " . self::MIN_SIZE_KB . " KB)");
                    $skipped++;
                }
            } catch (AwsException $e) {
                $output->writeln("  <error>Error on {$queueName}:</error> " . $e->getAwsErrorMessage());
                $errors++;
            }
        }

        $output->writeln('');
        $output->writeln('Done. Updated: ' . $updated . ', Skipped: ' . $skipped . ', Errors: ' . $errors);
        if ($dryRun && $updated > 0) {
            $output->writeln('<comment>Run without --dry-run to apply changes.</comment>');
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * @return SqsClient|null
     */
    private function getSqsClient()
    {
        $configPath = defined('BP') ? BP . '/app/etc/aws.php' : __DIR__ . '/../../../etc/aws.php';
        if (!is_readable($configPath)) {
            return null;
        }
        $config = include $configPath;
        // if (!is_array($config) || empty($config['credentials']['key'] ?? null) || empty($config['credentials']['secret'] ?? null)) {
        //     return null;
        // }
        // return new SqsClient([
        //     'version'     => $config['version'] ?? 'latest',
        //     'region'      => $config['region'],
        //     'credentials' => [
        //         'key'    => $config['credentials']['key'],
        //         'secret' => $config['credentials']['secret'],
        //     ],
        // ]);
        return new SqsClient($config);
    }

    /**
     * @param SqsClient $client
     * @return string[]
     */
    private function listAllQueueUrls(SqsClient $client)
    {
        $urls = [];
        $nextToken = null;
        do {
            $params = [];
            if ($nextToken !== null) {
                $params['NextToken'] = $nextToken;
            }
            $result = $client->listQueues($params);
            foreach ($result->get('QueueUrls') ?? [] as $url) {
                $urls[] = $url;
            }
            $nextToken = $result->get('NextToken');
        } while ($nextToken);
        return $urls;
    }
}