<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifynxtgen
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Core\Components\Message\Handler;

use App\Core\Components\Message\Handler\Sqs;
use Aws\Exception\AwsException;

class SqsS3Publisher extends Sqs
{
    // const MAX_SQS_SIZE_BYTES = 255 * 1024; // 255 KB safety limit
    const MAX_SQS_SIZE_BYTES = 1023 * 1024; // 1023 KB safety limit, after I updated the MaximumMessageSize of all queues

    /**
     * Push a message to SQS, automatically offloading large payloads to S3.
     * 
     * If message size is greater than 255 KB, the message body is first uploaded
     * to S3, and then a lightweight SQS message containing the S3 reference is sent.
     * Otherwise, the message is sent directly to SQS.
     *
     * @param array $data Must contain at least 'queue_name'
     * @return string|false Returns false on failure, otherwise returns success
     * @throws \Exception on AWS error
     */
    public function pushMessage($data)
    {
        // Handle update_parent_progress case (from parent class)
        if (isset($data['update_parent_progress'])) {
            return parent::pushMessage($data);
        }

        // Ensure queue_name exists
        if (empty($data['queue_name'])) {
            throw new \Exception('queue_name is required in message data');
        }

        // Prepare data with app_code/appTag (will be done by parent, but we need it for size check)
        $dataForSizeCheck = $data;
        if (!isset($dataForSizeCheck['appTag'])) {
            $dataForSizeCheck['appTag'] = $this->getDi()->getAppCode()->getAppTag();
        }
        if (!isset($dataForSizeCheck['appCode'])) {
            $dataForSizeCheck['appCode'] = $this->getDi()->getAppCode()->get();
        }
        if (!isset($dataForSizeCheck['app_code'])) {
            $dataForSizeCheck['app_code'] = $this->getDi()->getAppCode()->get();
        }

        // Check message size
        $json = json_encode($dataForSizeCheck);
        if ($json === false) {
            throw new \Exception('Failed to json_encode message data');
        }

        $size = strlen($json);

        // If message is small enough, use parent's pushMessage directly
        if ($size <= self::MAX_SQS_SIZE_BYTES) {
            return parent::pushMessage($data);
        }

        // Message is too large - upload to S3 first
        $bucket = $this->getDi()->getConfig()->get('sqs_s3_bucket');
        if (!$bucket) {
            throw new \Exception('sqs_s3_bucket is not configured in config');
        }

        // Generate unique S3 key
        $queueNameForPath = isset($data['queue_name']) ? $data['queue_name'] : 'default';
        $key = sprintf(
            'sqs-large/%s/%s-%s.json',
            $queueNameForPath,
            date('Ymd_His'),
            uniqid('', true)
        );

        // Upload full message to S3 using parent's getS3Client()
        try {
            $s3Client = $this->getS3Client();
            $s3Client->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $json,
            ]);
        } catch (AwsException $e) {
            throw new \Exception(
                // 'Error uploading payload to S3: ' . $e->getAwsErrorMessage(),
                'Error uploading payload to S3: ' . $e->getMessage(),
                $e->getCode()
            );
        }

        // Build lightweight SQS message with S3Payload reference
        // This format is already handled by the consumer (see Handler/Sqs.php consume method)
        $sqsMessage = [
            'S3Payload' => [
                'bucketName' => $bucket,
                'Key'        => $key,
            ],
        ];

        // Preserve important routing/metadata fields for the consumer
        if (isset($data['user_id'])) {
            $sqsMessage['user_id'] = $data['user_id'];
        }
        if (isset($data['username'])) {
            $sqsMessage['username'] = $data['username'];
        }
        if (isset($data['appCode'])) {
            $sqsMessage['appCode'] = $data['appCode'];
        }
        if (isset($data['app_code'])) {
            $sqsMessage['app_code'] = $data['app_code'];
        }
        if (isset($data['appTag'])) {
            $sqsMessage['appTag'] = $data['appTag'];
        }
        if (isset($data['current-App'])) {
            $sqsMessage['current-App'] = $data['current-App'];
        }
        if (isset($data['queue_name'])) {
            $sqsMessage['queue_name'] = $data['queue_name'];
        }
        if (isset($data['delay'])) {
            $sqsMessage['delay'] = $data['delay'];
        }
        if (isset($data['run_after'])) {
            $sqsMessage['run_after'] = $data['run_after'];
        }
        if (isset($data['visibility_timeout'])) {
            $sqsMessage['visibility_timeout'] = $data['visibility_timeout'];
        }

        // Use parent's pushMessage to send the lightweight SQS message
        return parent::pushMessage($sqsMessage);
    }
}