#!/usr/bin/env php
<?php
/**
 * Update MaximumMessageSize of all SQS queues to 1024 KB if currently less than 1024 KB.
 *
 * Usage:
 *   php update_sqs_max_message_size.php [--config=PATH] [--dry-run]
 *
 * Options:
 *   --config=PATH  Path to AWS config PHP file (default: ../app/etc/aws.php relative to script)
 *   --dry-run      Only list queues and what would be updated; do not apply changes
 *
 * Requires: aws/aws-sdk-php (use composer from project root if needed)
 */

$minSizeKb = 1024;
$targetSizeBytes = 1024 * 1024; // 1024 KB = 1 MiB

$options = getopt('', ['config:', 'dry-run']);
$configPath = $options['config'] ?? __DIR__ . '/../app/etc/aws.php';
$dryRun = isset($options['dry-run']);

if (!is_readable($configPath)) {
    fwrite(STDERR, "Error: AWS config not found or not readable: {$configPath}\n");
    fwrite(STDERR, "Use --config=/path/to/aws.php or ensure default path exists.\n");
    exit(1);
}

$awsConfig = include $configPath;
if (!is_array($awsConfig) || empty($awsConfig['credentials']['key'] ?? null) || empty($awsConfig['credentials']['secret'] ?? null)) {
    fwrite(STDERR, "Error: Invalid AWS config. Must return array with 'credentials' (key, secret) and 'region'.\n");
    exit(1);
}

// Autoload AWS SDK (prefer project vendor)
$vendorPaths = [
    __DIR__ . '/../vendor/autoload.php',   // home/vendor when run from home/scripts
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
$loaded = false;
foreach ($vendorPaths as $path) {
    if (is_file($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "Error: AWS SDK not found. Run 'composer require aws/aws-sdk-php' from project root.\n");
    exit(1);
}

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

$client = new SqsClient([
    'version'     => $awsConfig['version'] ?? 'latest',
    'region'      => $awsConfig['region'],
    'credentials' => [
        'key'    => $awsConfig['credentials']['key'],
        'secret' => $awsConfig['credentials']['secret'],
    ],
]);

echo "Listing SQS queues (region: {$awsConfig['region']})...\n";
$queueUrls = [];
$nextToken = null;
do {
    $params = [];
    if ($nextToken !== null) {
        $params['NextToken'] = $nextToken;
    }
    $result = $client->listQueues($params);
    foreach ($result->get('QueueUrls') ?? [] as $url) {
        $queueUrls[] = $url;
    }
    $nextToken = $result->get('NextToken');
} while ($nextToken);

echo "Found " . count($queueUrls) . " queue(s).\n";
if (empty($queueUrls)) {
    exit(0);
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

        if ($currentBytes < $targetSizeBytes) {
            if ($dryRun) {
                echo "  [DRY-RUN] Would update: {$queueName} (current: {$currentKb} KB -> {$minSizeKb} KB)\n";
            } else {
                $client->setQueueAttributes([
                    'QueueUrl'   => $queueUrl,
                    'Attributes' => [
                        'MaximumMessageSize' => (string) $targetSizeBytes,
                    ],
                ]);
                echo "  Updated: {$queueName} ({$currentKb} KB -> {$minSizeKb} KB)\n";
            }
            $updated++;
        } else {
            echo "  Skip: {$queueName} (already {$currentKb} KB >= {$minSizeKb} KB)\n";
            $skipped++;
        }
    } catch (AwsException $e) {
        fwrite(STDERR, "  Error on {$queueName}: " . $e->getAwsErrorMessage() . "\n");
        $errors++;
    }
}

echo "\nDone. Updated: {$updated}, Skipped: {$skipped}, Errors: {$errors}\n";
if ($dryRun && $updated > 0) {
    echo "Run without --dry-run to apply changes.\n";
}
exit($errors > 0 ? 1 : 0);
