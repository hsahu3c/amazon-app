<?php
/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 28/2/23
 * Time: 2:53 PM
 */
namespace App\Shopifyhome\Components\Product\Cron;

use App\Shopifyhome\Components\Core\Common;

class CatalogSync extends Common
{
    public function StartSyncingForProducts(): void
    {
        // _id ko DESC order mai sort karke user ids pick karenge
        //and jo bhi users ki importing chalegi cron par unki entry config mai store karwaenge
        //uske baad jaise app flow ki importing hai as it is he work karegi
        //config se data pick karenge users ka jisnka humne import initaite kara hoga and uske baad unn users ki entry check karenge queued tasks mai and agar usme se kisi bhi user ki entry nahi mili toh next 5 users ko pick kar k unka importing initaite karenge and agar hamaram import cron k through complete ho gaya tha and uske baad BDA ne chalaya import admin panel se toh usko cron mai consider nahi kiya jaega, queued task mai process code alag rahega cron k through importing k data mai
        $get_users = $this->getUser();

    }

    public function getUser()
    {
        // find the users in config table that were stored when the cron was initiated

        $userIds = [];
        $checkImport = $this->importInProgress($userIds);
        if(isset($checkImport['status']) && $checkImport['status'])
        {
            return ['status' => false,'message' => 'Previously picked merchants are already in progress'];
        }
        if (isset($checkImport['data']) && !empty($checkImport['data']))
        {
            //if importing of prevoiusly picked merchant is completed and now we need to find new merchants
            $user_id = $checkImport['data'];

            $mongoObj = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongoObj->getCollection('config');

            $newUsers = 'query for findinf new  users to start import';

            return ['status' => true,'data' => ' user_ids'];

        }
        // this condition will run only first time or the fetching users data will return us blank which means that all the users are initiated with import via cron and no users in db exist higher than with the last synced _id of users
        $users = 'query to find the 5 users based on oldest to newest';
        return ['status' => true,'data' => ' user_ids'];
    }

    public function importInProgress($userIds)
    {
        // check the entry in queued task for importing

        $importingInProgress = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('queued_tasks');
        $userData = $collection->findOne(
            [
                'appTag' => 'amazon_sales_channel',
                'process_code' => 'cron_saleschannel_product_import'
            ],
            [
                "typeMap" =>
                    [
                        'root' => 'array',
                        'document' => 'array'
                    ],
                "projection" => ['user_id' => 1]
            ]);

        if($importingInProgress)
        {
            return['status' => true, 'message' => 'Importing in progress'];
        }
        $collection = $mongo->getCollection('config');
        $query = $collection->find(['']);
        return ['status' => false, 'message' => 'Importing completed', 'data' => 'highest__id'];
    }
}