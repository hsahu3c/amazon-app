<?php

namespace App\Plan\Models;

use App\Plan\Components\Common;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Models\Plan;
use Exception;

/**
 * Class Plan
 * @package App\Plan\Models
 */
class Transaction extends \App\Core\Models\BaseMongo
{

    public const TRANSACTION_COLLECTION = 'transaction_details';

    public const TRANSACTION_TYPE_PAYMENT = 'payment_transaction';

    public $data;

    public function init($userId): void
    {
        $this->data['user_id'] = $userId;
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $diResponse = $this->di->getObjectManager()->get(Common::class)->setDiForUser($userId);
        $this->data['type'] = self::TRANSACTION_TYPE_PAYMENT;
        if($diResponse['success']) {
            $this->data['user_status'] = 'active';
            if($planModel->isPlanActive($userId)) {
                $this->data['plan_status'] = 'active';
            } else {
                $this->data['plan_status'] = 'inactive';
            }

            $this->data['sync_activated'] = $planModel->isSyncAllowed();
        } else {
            $this->data['user_status'] = 'inactive';
        }

        $this->data['month'] = date('m');
        $this->data['year'] = date('Y');
    }
    

    public function setData($data): void
    {
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    public function saveData($userId = null): void
    {
        if(!empty($userId) && !empty($this->data)) {
            $this->init($userId);
            $this->data['updated_at'] = date('c');
            if (!isset($this->data['active_plan_details'])) {
                $planModel = $this->di->getObjectManager()->get(Plan::class);
                $this->data['active_plan_details'] = $planModel->getActivePlanForCurrentUser($userId);
            }

            if (isset($this->data['_id'])) {
                unset($this->data['_id']);
            }

            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $transactionCollection = $baseMongo->getCollectionForTable(self::TRANSACTION_COLLECTION);
            try {
                $transactionCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'month' => date('m'),
                        'year' => date('Y')
                    ],
                    [
                        '$set'=> $this->data
                    ],
                    ['upsert' => true]
                );
            } catch(Exception $e) {
                $this->di->getLog()->logContent(
                    print_r($e->getMessage(), true),
                    'info',
                    'plan/transaction/mongo_error.log'
                );
            }
        }
    }

    public function getAll($userId = null)
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $transactionCollection = $baseMongo->getCollectionForTable(self::TRANSACTION_COLLECTION);
        if(!empty($userId)) {
            return $transactionCollection->find(
                [
                    'user_id' => $userId
                ],
                $options
            )->toArray();
        }

        return $transactionCollection->find([], $options)->toArray();
    }

    public function getCurrentTransaction($userId = null)
    {
        if(!empty($userId)) {
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $transactionCollection = $baseMongo->getCollectionForTable(self::TRANSACTION_COLLECTION);
            return $transactionCollection->findOne(
                [
                    'user_id' => $userId,
                    'month' => date('m'),
                    'year' => date('Y')
                ],
                $options
            );
        }

        return [];
    }
}
