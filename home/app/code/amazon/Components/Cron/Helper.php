<?php

namespace App\Amazon\Components\Cron;

use App\Core\Components\Base as Base;

class Helper extends Base
{
    public function getOrderSyncQueueName()
    {
        return 'amazon_order_sync';
    }

    public function getShipmentSyncQueueName()
    {
        return 'amazon_shipment_sync';
    }

    public function getInvnetoryCronQueueName()
    {
        return 'amazon_bulk_user_inventory_sync';
    }

    public function getFeedSyncQueueName()
    {
        return 'amazon_feed_sync';
    }

    public function reportRequestQueueName()
    {
        return 'amazon_report_request';
    }

    public function imageImportQueueName()
    {
        return 'amazon_product_image';
    }

    /*public function getReportQueueName()
    {
        return 'amazon_get_report';
    }*/

    /*public function moldReportQueueName()
    {
        return 'amazon_mold_report';
    }*/

    public function getSearchOnAmazonQueueName()
    {
        return 'amazon_product_search';
    }

    /*public function getMatchProductQueueName()
    {
        return 'amazon_product_match';
    }*/

    public function getProductReportQueueName()
    {
        if ($this->di->getUser()->id == "68058af274e396dc230f22d3") {
            return 'amazon_product_custom_report';
        }
        return 'amazon_product_report';
    }

    public function getAmazonListingDeleteQueueName()
    {
        return 'amazon_listing_delete';
    }

    public function getProductUploadQueueName()
    {
        return 'amazon_product_upload';
    }

    public function getPriceSyncQueueName()
    {
        return 'amazon_price_sync';
    }

    public function getInventorySyncQueueName()
    {
        return 'amazon_inventory_sync';
    }

    public function getImageSyncQueueName()
    {
        return 'amazon_image_sync';
    }
    public function getPriceCronQueueName()
    {
        return 'amazon_bulk_user_price_sync';
    }

    public function getProductDeleteQueueName()
    {
        return 'amazon_product_delete';
    }

    public function getBarcodeOrTitleMatchQueueName()
    {
        return 'amazon_barcode_or_title_match';
    }

    public function getFailedOrderSyncQueueName()
    {
        return 'amazon_failed_order_sync';
    }

    public function automaticCancelOrdersBOPIS()
    {
        return 'amazon_cancel_order_BOPIS';
    }

    public function automaticRefundOrdersBOPIS()
    {
        return 'amazon_refund_order_BOPIS';
    }
}
