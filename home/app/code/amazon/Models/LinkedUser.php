<?php

namespace App\Amazon\Models;

use App\Core\Models\BaseMongo;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class LinkedUser extends BaseMongo
{
    protected $table = 'linked_users';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * Link a user to a seller based on provided data.
     *
     * @param string $amazonUserId
     * @param array $amazonUserData
     * @param string $shopUrl
     * @param string $loggedInUserId
     * @return array
     */
    public function linkUserToSeller($amazonUserId, $amazonUserData, $shopUrl, $loggedInUserId, $postData)
    {
        // Finding seller by shop domain
        $userDetailsCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable('user_details');
        $seller = $userDetailsCollection->findOne(['username' => $shopUrl]);

        if (!$seller) {
            return [
                'success' => false,
                'message' => 'Seller not found to link Amazon user with that seller'
            ];
        }

        // Check if Amazon user is already linked
        $linkedUser = $this->getCollection()->findOne([
            'amazon_user_id' => $amazonUserId,
            'widget' => $postData['widget'],
            'seller_id' => new ObjectId($seller['_id']),
            'user_id' => new ObjectId($loggedInUserId)
        ]);

        if ($linkedUser) {
            return [
                'success' => true,
                'seller_id' => (string) $linkedUser['seller_id'],
                'message' => 'User already linked'
            ];
        }

        // Create a new entry in linked_users collection
        $insertResult = $this->getCollection()->insertOne([
            'amazon_user_id' => $amazonUserId,
            'amazon_user_email' => $amazonUserData['email'],
            'amazon_user_name' => $amazonUserData['name'],
            'amazon_user_postal_code' => $amazonUserData['postal_code'],
            'widget' => $postData['widget'],
            'seller_id' => new ObjectId($seller['_id']),
            'seller_username' => $seller['username'],
            'user_id' => new ObjectId($loggedInUserId),
            'linked_at' => new UTCDateTime()
        ]);

        if ($insertResult->getInsertedCount() > 0) {
            // Link the Amazon user ID to the seller (user_details collection)
            $userDetailsCollection->updateOne(
                ['_id' => new ObjectId($seller['_id'])],
                ['$push' => ['linked_amazon_users' => $amazonUserId]]
            );

            return [
                'success' => true,
                'seller_id' => (string) $seller['_id'],
                'message' => 'User linked successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to link Amazon user'
            ];
        }
    }

    public function getLinkedAccounts($amazonUserId, $widget, $userId = null, $sellerId = null)
    {
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $linkedUser = $this->di->getObjectManager()->get(LinkedUser::class);

        $filter = [
            'amazon_user_id' => $amazonUserId,
            'widget' => $widget
        ];
        if ($userId) {
            $filter['user_id'] = new ObjectId($userId);
        }

        if ($sellerId) {
            $filter['seller_id'] = new ObjectId($sellerId);
        }

        $linkedUser = $linkedUser->find($filter, $options)->toArray();

        return $this->convertObjectIdsToStrings($linkedUser);
    }
    private function convertObjectIdsToStrings($data)
    {
        foreach ($data as &$item) {
            if (is_array($item)) {
                $item = $this->convertObjectIdsToStrings($item);
            } elseif ($item instanceof ObjectId) {
                $item = (string) $item;
            } elseif ($item instanceof UTCDateTime) {
                $item = $item->toDateTime()->format(DATE_ISO8601);
            }
        }
        return $data;
    }

}
