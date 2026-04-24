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

namespace App\Core\Components;

use Exception;
use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;


class Sqs extends Base
{

    public function createQueue($queueName, $sqsClient, $visibilityTimeout = 2 * 60)
    {
        try {
            $queueUrl = $this->getQueueUrl($queueName, $sqsClient);
            if ($queueUrl === false) {
                $result = $sqsClient->createQueue([
                    'QueueName'  => $queueName,
                    'Attributes' => [
                        'DelaySeconds'       => 0,
                        'MaximumMessageSize' => (string)(64 * 4096), // 256 KB
                        'VisibilityTimeout' => (string)$visibilityTimeout, // 2 min max time to process the message
                    ]
                ]);

                $queueUrl = $result->get('QueueUrl');
            }

            if ($queueUrl) {
                return [
                    'success'   => true,
                    'queue_url'  => $queueUrl
                ];
            } else {
                return [
                    'success'    => false,
                    'msg'        => 'something went wrong in sqs'
                ];
            }
        } catch (AwsException $e) {
            return [
                'success'    => false,
                'msg'        => 'Sqs credentials are not valid',
                'error'      => $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            return [
                'success'   => false,
                'msg'       => 'Sqs credentials are not valid',
                'error'     => $e->getMessage()
            ];
        }
    }

    public function getQueueUrl($queueName, $client)
    {
        $queueUrl = false;
        try {
            $result = $client->getQueueUrl([
                'QueueName' => $queueName,
            ]);
            $queueUrl = $result->get('QueueUrl');
        } catch (AwsException $e) {
        }
        return $queueUrl;
    }
    public function getClient($region, $key = null, $secret = null)
    {
        $config = [
            'version' => 'latest',
            'region' => $region,
        ];
        if ($key && $secret) {
            $config['credentials'] = [
                'key' => $key,
                'secret' => $secret
            ];
        }
        return new SqsClient($config);
    }
}
