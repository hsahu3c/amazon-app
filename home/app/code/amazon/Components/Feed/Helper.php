<?php


namespace App\Amazon\Components\Feed;

use App\Core\Components\Base;

class Helper extends Base
{
    public const FEED_FORMAT = [
        'Feed Id' => 'feed_id',
        'Status' => 'status',
        'Type' => 'type',
        'Equals' => 1,
        'Contains' => 3,
        'Submitted' => '_SUBMITTED_',
        'INQUEUED' => 'IN_QUEUE',
        'Done' => '_DONE_',
        'Product Upload' => 'POST_FLAT_FILE_LISTINGS_DATA',
        'Product Inventory' => '_POST_INVENTORY_AVAILABILITY_DATA_',
        'Product Price' => 'POST_PRODUCT_PRICING_DATA',
        'Product price' => 'POST_PRODUCT_PRICING_DATA',
        'Product Image' => '_POST_PRODUCT_IMAGE_DATA_',
        'Product Relationship' => '_POST_PRODUCT_RELATIONSHIP_DATA_',
        'Order Fullfillment' => '_POST_ORDER_FULFILLMENT_DATA_',
        'Order-Fullfillment' => 'POST_ORDER_FULFILLMENT_DATA',
        'Product Delete' => '_POST_PRODUCT_DATA_',
        'JSON Listings Feed' => 'JSON_LISTINGS_FEED',
        'JSON Inventory' => 'JSON_LISTINGS_FEED_INVENTORY',
        'JSON Image' => 'JSON_LISTINGS_FEED_IMAGE',
        'JSON Delete' => 'JSON_LISTINGS_FEED_DELETE',
        'JSON Price' => 'JSON_LISTINGS_FEED_PRICE',
        'JSON Upload' => 'JSON_LISTINGS_FEED_UPLOAD',
        'JSON Update' => 'JSON_LISTINGS_FEED_UPDATE',
        'JSON Feed' => 'JSON_LISTING_FEED'

    ];

    public function getAllFeeds($data) {
        $res = $this->di->getObjectManager()->get(Feed::class)
            ->init($data)->getAll($data);
        return $res;
    }

    public function getFeedResponseView($data) {
        $res = $this->di->getObjectManager()->get(Feed::class)
            ->init($data)->getFeedResponse($data);
        return $res;
    }

    public function getFeedTypes()
    {
        return ['success' => true , 'data' => self::FEED_FORMAT];
    }

    public function syncFeed($data) {
        $res = $this->di->getObjectManager()->get(Feed::class)
            ->init($data)
            ->sync($data);
        return $res;
    }

    public function deleteFeed($data) {
        $res = $this->di->getObjectManager()->get(Feed::class)
            ->init()
            ->delete($data);
        return $res;
    }


    public function getBucketData($data) {
        $res = $this->di->getObjectManager()->get(Feed::class)
            ->init()
            ->getBucketData($data);
        return $res;
    }

    public function handleCancelledFeed($sqsData) {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $feedContainer = $mongo->getCollectionForTable('feed_container');
            $feedData = $sqsData['data'];
            $feedData['user_id'] = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $feedData['shop_id'] = $sqsData['shop_id'];
            $feedContainer->insertOne($feedData);
        } catch(\Exception $e) {
            $this->di->getLog()->logContent('Exception from handleCancelledFeed() : ' . $e->getMessage(), 'info', 'exception.log');
            throw $e;
        }

    }
}