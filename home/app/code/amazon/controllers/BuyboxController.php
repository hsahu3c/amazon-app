<?php
namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Buybox\Buybox;

class BuyboxController extends BaseController
{
    public function init()
    {
        $this->di->getObjectManager()->create(Buybox::class)->isBuyBoxFeatureAllowed($this->di->getUser()->id);
    }

    /**
     * Fetch all Buybox items count
     */
    public function getBuyboxCountAction()
    {
        try {
            $this->init();
            $userId = $this->di->getUser()->id;
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['target']['shopId'] ?? null;
            if(!$shopId){
                return $this->prepareResponse(['success' => false, 'message' => 'Target Shop Id is required']);
            }
            // $buyboxComponent = new Buybox();
            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $win_count = $buyboxComponent->getBuyboxWinCount($userId, $shopId);
            $loss_count = $buyboxComponent->getBuyboxLossCount($userId, $shopId);

            return $this->prepareResponse(['success' => true, 'win-count' => $win_count, 'loss-count' => $loss_count]);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Fetch all Buybox win items
     */
    public function getBuyboxWinCountAction()
    {
        try {
            $this->init();
            $userId = $this->di->getUser()->id;
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['target']['shopId'] ?? null;
            if(!$shopId){
                return $this->prepareResponse(['success' => false, 'message' => 'Target Shop Id is required']);
            }
            // $buyboxComponent = new Buybox();
            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $count = $buyboxComponent->getBuyboxWinCount($userId, $shopId);
            return $this->prepareResponse(['success' => true, 'count' => $count]);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Fetch all Buybox loss items
     */
    public function getBuyboxLossCountAction()
    {
        try {
            $this->init();
            $userId = $this->di->getUser()->id;
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['target']['shopId'] ?? null;
            if(!$shopId){
                return $this->prepareResponse(['success' => false, 'message' => 'Target Shop Id is required']);
            }
            // $buyboxComponent = new Buybox();
            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $count = $buyboxComponent->getBuyboxLossCount($userId, $shopId);
            return $this->prepareResponse(['success' => true, 'count' => $count]);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Fetch paginated buybox items with filtering and selected fields
     *
     * POST body (JSON):
     * {
     *   "page": 1,
     *   "filters": {
     *     "title": "...",
     *     "sku": "...",
     *     "asin": "...",
     *     "buybox": "win"|"loss",
     *     "issue_type": "..."
     *   },
     *   "source":{
     *     "shopId":"...",
     *     "marketplace":"shopify"
     *   },
     *   "target":{
     *     "shopId":"...",
     *     "marketplace":"amazon"
     *   },
     * }
     */
    public function getBuyboxItemsAction()
    {
        try {
            $this->init();
            $userId = $this->di->getUser()->id;
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['target']['shopId'] ?? null;
            if(!$shopId){
                return $this->prepareResponse(['success' => false, 'message' => 'Target Shop Id is required']);
            }
            $page = isset($rawBody['page']) && (int)$rawBody['page'] > 0 ? (int)$rawBody['page'] : 1;
            $limit = 20;
            $filters = $rawBody['filters'] ?? [];
            // $buyboxComponent = new Buybox();
            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $result = $buyboxComponent->getBuyboxItems($userId, $shopId, $filters, $page, $limit);
            return $this->prepareResponse([
                'success' => true,
                'data' => $result['results'],
                'page' => $page,
                'total' => $result['total'],
                'pages' => ceil($result['total'] / $limit)
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getOfferDetailsAction()
    {
        try {
            $this->init();
            $userId = $this->di->getUser()->id;
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['target']['shopId'] ?? null;
            if(!$shopId){
                return $this->prepareResponse(['success' => false, 'message' => 'Target Shop Id is required']);
            }

            $asin = $rawBody['asin'] ?? null;
            if (!$asin) {
                return $this->prepareResponse(['success' => false, 'message' => 'ASIN is required']);
            }
            // $buyboxComponent = new Buybox();
            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $data = $buyboxComponent->getOfferDetails($userId, $shopId, $asin);
            // return $this->prepareResponse(['success' => true, 'data' => $data]);
            return $this->prepareResponse($data);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Create Destination for Pricing notifications (PRICING_HEALTH & ANY_OFFER_CHANGED) for Amazon
     * POST body: {"shopId": "<HomeShopId of Amazon Shop>"} 
     */
    public function createDestinationForPricingNotificationAction()
    {
        try {
            $this->init();
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['shop_id'] ?? null;
            $userId = $this->di->getUser()->id;

            if (!$shopId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'shop_id is required'
                ]);
            }

            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $response = $buyboxComponent->createDestinationForPricingNotification($userId, $shopId);
            
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Subscribe to PRICING_HEALTH and ANY_OFFER_CHANGED notifications for Amazon
     * POST body: {"shopId": "<HomeShopId of Amazon Shop>"}
     */
    public function subscribePricingNotificationAction()
    {
        try {
            $this->init();
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['shop_id'] ?? null;
            $userId = $this->di->getUser()->id;

            if (!$shopId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'shop_id is required'
                ]);
            }

            $buyboxComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\Buybox');
            $response = $buyboxComponent->subscribePricingNotification($userId, $shopId);
            
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Initiate Amazon Buybox Status Batch Process
     * POST body: {"shop_id": "<HomeShopId of Amazon Shop>", "process_initiation": "manual|automatic"}
     */
    public function initiateBatchProcessAction()
    {
        try {
            $this->init();
            $rawBody = $this->getRequestData();
            $shopId = $rawBody['shop_id'] ?? null;
            $userId = $this->di->getUser()->id;

            if (!$shopId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'shop_id is required'
                ]);
            }

            $options = ['process_initiation' => $rawBody['process_initiation'] ?? 'manual'];
            if(isset($rawBody['skus']) && !empty($rawBody['skus']) && is_array($rawBody['skus'])) {
                $options['skus'] = $rawBody['skus'];
            }

            // $batchService = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\FeaturedOfferExpectedPriceBatch');
            $batchService = $this->di->getObjectManager()->create('\App\Amazon\Components\Buybox\ListingOffersBatch');
            $response = $batchService->initiateBatchProcess($userId, $shopId, $options);
            
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
} 