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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product\Vistar;

use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Core\Common;

class Data extends Common
{
    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;

        $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> sqs data sent ======>   '.json_encode($data),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'data_sent_on_sqs.log');

        $push = $this->di->getMessageManager()->pushMessage($data);
        return true;
    }

    public function completeProgressBar($sqsData, $msg = "Error fetching data from Shopify. Kindly contact support for help.", $severity = 'critical'): void
    {
        $progress = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], 100);
        if ($progress && $progress == 100)
        {
            $notificationData = [
                'marketplace' => $sqsData['data']['shop']['marketplace'],
                'user_id' => $sqsData['data']['user_id'],
                'message' => $msg,
                'severity' => $severity,
                'process_code' => $sqsData['process_code'] ?? 'product_import'
            ];

            if(!empty($sqsData['additional_data'])) {
                $notificationData['additional_data'] = $sqsData['additional_data'];
            }

            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($sqsData['data']['shop']['_id'], $notificationData);
        }
    }

    public function updateQueueMessage($userId, $stringToMatch, $msg): void{
        $queuedTask = new QueuedTasks;
        $queueModel = $queuedTask::findFirst(
            [
                'conditions' => 'user_id ='.$userId.' AND message LIKE "%'.$stringToMatch.'%"'
            ]
        );
        if($queueModel){
            $queueModel->message = $msg;
            $queueModel->save();
        }
    }
}