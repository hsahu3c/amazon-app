<?php
namespace App\Amazon\Components\Buybox;

use App\Core\Models\BaseMongo;
use App\Core\Components\Base;
use App\Amazon\Components\Buybox\ListingOffersBatch;

class Buybox extends Base
{
    // const PRICING_HEALTH_TABLE = 'amazon_pricing_health';
    // const PRICING_OFFERS_TABLE = 'amazon_pricing_offers';

    protected $mongo;

    public function __construct()
    {
        $this->mongo = new BaseMongo();
    }

    public function getBuyboxWinCount($userId, $shopId)
    {
        // $collection = $this->mongo->getCollectionForTable(self::PRICING_HEALTH_TABLE);
        $collection = $this->mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE);
        $filter = ['buybox' => 'win', 'user_id' => $userId, 'shop_id' => $shopId];
        return $collection->count($filter);
    }

    public function getBuyboxLossCount($userId, $shopId)
    {
        // $collection = $this->mongo->getCollectionForTable(self::PRICING_HEALTH_TABLE);
        $collection = $this->mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE);
        $filter = ['buybox' => 'loss', 'user_id' => $userId, 'shop_id' => $shopId];
        return $collection->count($filter);
    }

    public function getBuyboxItems($userId, $shopId, $filters, $page, $limit)
    {
        // $collection = $this->mongo->getCollectionForTable(self::PRICING_HEALTH_TABLE);
        $collection = $this->mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE);
        $skip = ($page - 1) * $limit;
        $query = ['user_id' => $userId, 'shop_id' => $shopId];
        if (!empty($filters['title'])) {
            $query['title'] = ['$regex' => preg_quote($filters['title'],'\\\\'), '$options' => 'i'];
        }
        if (!empty($filters['sku'])) {
            $query['sku'] = ['$regex' => $filters['sku'], '$options' => 'i'];
        }
        if (!empty($filters['asin'])) {
            $query['asin'] = ['$regex' => $filters['asin'], '$options' => 'i'];
        }
        if (!empty($filters['buybox'])) {
            $query['buybox'] = $filters['buybox'];
        }
        if (!empty($filters['issue_type'])) {
            $query['issue_type'] = $filters['issue_type'];
        }
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'limit' => $limit,
            'skip' => $skip,
            'projection' => [
                'image' => 1,
                'title' => 1,
                'sku' => 1,
                'buybox' => 1,
                'asin' => 1,
                'listing_id' => 1,
                'item_condition' => 1,
                // 'issue_type' => 1,
                // 'pricing_health.merchant_offer' => 1,
                // 'pricing_health.summary.buyBoxPrices' => 1,
                // '_id' => 0,
                'offer_status' => 1,
                'error' => 1,
                // 'featured_offer' => 1,
                'summary' => 1,
                'created_at' => 1,
                'updated_at' => 1
            ]
        ];
        $results = $collection->find($query, $options)->toArray();
        $total = $collection->count($query);
        return [
            'success' => true,
            'results' => $results,
            'total' => $total
        ];
    }

    // public function getOfferDetails($userId, $shopId, $asin)
    // {
    //     $mongo = $this->mongo;
    //     $filter = ['user_id' => $userId, 'shop_id' => $shopId, 'asin' => $asin];

    //     $options = [
    //         'typeMap' => ['root' => 'array', 'document' => 'array'],
    //         'projection' => [
    //             'image' => 1,
    //             'title' => 1,
    //             'sku' => 1,
    //             'buybox' => 1,
    //             'asin' => 1,
    //             'listing_id' => 1,
    //             'item_condition' => 1,
    //             // 'pricing_health.merchant_offer' => 1,
    //             // 'pricing_health.summary.buyBoxPrices' => 1,
    //             // '_id' => 0
    //             'offer_status' => 1,
    //             'error' => 1,
    //             // 'featured_offer' => 1,
    //             'offers' => 1,
    //             'created_at' => 1,
    //             'updated_at' => 1
    //         ]
    //     ];
    //     $pricingHealth = $mongo->getCollectionForTable(self::PRICING_HEALTH_TABLE)->findOne($filter, $options);

    //     if($pricingHealth['offer_status'] === 'ERROR') {
    //         return [
    //             'success' => false, 'message' => 'Offer does not exists.'
    //         ];
    //     }
    //     else {
    //         $offersFilter = ['user_id' => $userId, 'shop_id' => $shopId, 'asin' => $asin];
    //         $offersOptions = [
    //             'typeMap' => ['root' => 'array', 'document' => 'array'],
    //             'projection' => [
    //                 'offers_info.offers' => 1,
    //                 '_id' => 0
    //             ]
    //         ];
    //         $offersDoc = $mongo->getCollectionForTable(self::PRICING_OFFERS_TABLE)->findOne($offersFilter, $offersOptions);
    //         if(empty($offersDoc)) {
    //             // Get shop details for API credentials
    //             $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //             $shop = $userDetails->getShop($shopId, $userId);
                
    //             if (!$shop) {
    //                 return ['success' => false, 'message' => 'Shop not found'];
    //             }

    //             // Prepare API request data
    //             $requestData = [
    //                 'shop_id' => $shop['remote_shop_id'],
    //                 'item_condition' => $pricingHealth['item_condition'],
    //                 'sku' => $pricingHealth['sku']
    //             ];

    //             // Use the existing Helper to send request to Amazon
    //             $helper = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Helper');
    //             $response = $helper->sendRequestToAmazon('listing-offer', $requestData, 'GET');

    //             if (isset($response['success']) && $response['success']) {
    //                 $resp = $this->savePricingOffer($userId, $shopId, $pricingHealth, $response);
    //                 if($resp['success'] === false) {
    //                     return $resp;
    //                 }
    //                 else {
    //                     $offersDoc = $resp['data'];
    //                 }
    //             }
    //             else {
    //                 return ['success' => false, 'message' => $response['error'] ?? 'API call failed'];
    //             }
    //         }

    //         $offers = $offersDoc['offers_info']['offers'] ?? [];
    //         // Find the single buybox winner among all offers
    //         $offer_buybox = null;
    //         $offers_fba = [];
    //         $offers_mfn = [];
    //         foreach ($offers as $offer) {
    //             $isFba = isset($offer['IsFulfilledByAmazon']) && $offer['IsFulfilledByAmazon'];
    //             $isBuyBox = isset($offer['IsBuyBoxWinner']) && $offer['IsBuyBoxWinner'];
    //             if ($isBuyBox && $offer_buybox === null) {
    //                 $offer_buybox = $offer;
    //                 continue;
    //             }
    //             if ($isFba) {
    //                 $offers_fba[] = $offer;
    //             } else {
    //                 $offers_mfn[] = $offer;
    //             }
    //         }
    //         // Remove buybox winner from fba/mfn arrays if present
    //         if ($offer_buybox) {
    //             $offers_fba = array_filter($offers_fba, function($o) use ($offer_buybox) {
    //                 return $o !== $offer_buybox;
    //             });
    //             $offers_mfn = array_filter($offers_mfn, function($o) use ($offer_buybox) {
    //                 return $o !== $offer_buybox;
    //             });
    //             $offers_fba = array_values($offers_fba);
    //             $offers_mfn = array_values($offers_mfn);
    //         }

    //         return [
    //             'success' => true,
    //             'data' => [
    //                 'image' => $pricingHealth['image'] ?? null,
    //                 'title' => $pricingHealth['title'] ?? null,
    //                 'sku' => $pricingHealth['sku'] ?? null,
    //                 'buybox' => $pricingHealth['buybox'] ?? null,
    //                 // 'merchant_offer' => $pricingHealth['pricing_health']['merchant_offer'] ?? null,
    //                 // 'buyBoxPrices' => $pricingHealth['pricing_health']['summary']['buyBoxPrices'] ?? null,
    //                 'offer_buybox' => $offer_buybox,
    //                 'offers_fba' => $offers_fba,
    //                 'offers_mfn' => $offers_mfn
    //             ]
    //         ];
    //     }
    // }
    public function getOfferDetails($userId, $shopId, $asin)
    {
        $mongo = $this->mongo;
        $filter = ['user_id' => $userId, 'shop_id' => $shopId, 'asin' => $asin];

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => [
                'image' => 1,
                'title' => 1,
                'sku' => 1,
                'buybox' => 1,
                'asin' => 1,
                'listing_id' => 1,
                'item_condition' => 1,
                'offer_status' => 1,
                'error' => 1,
                'summary' => 1,
                'offers' => 1,
                'created_at' => 1,
                'updated_at' => 1
            ]
        ];
        $offersDoc = $this->mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE)->findOne($filter, $options);

        if(!empty($offersDoc)) {
            $offers = $offersDoc['offers'] ?? [];
            // Find the single buybox winner among all offers
            $offer_buybox = null;
            $offers_fba = [];
            $offers_mfn = [];
            foreach ($offers as $offer) {
                $isFba = isset($offer['IsFulfilledByAmazon']) && $offer['IsFulfilledByAmazon'];
                $isBuyBox = isset($offer['IsBuyBoxWinner']) && $offer['IsBuyBoxWinner'];
                if ($isBuyBox && $offer_buybox === null) {
                    $offer_buybox = $offer;
                    continue;
                }
                if ($isFba) {
                    $offers_fba[] = $offer;
                } else {
                    $offers_mfn[] = $offer;
                }
            }
            // Remove buybox winner from fba/mfn arrays if present
            if ($offer_buybox) {
                $offers_fba = array_filter($offers_fba, function($o) use ($offer_buybox) {
                    return $o !== $offer_buybox;
                });
                $offers_mfn = array_filter($offers_mfn, function($o) use ($offer_buybox) {
                    return $o !== $offer_buybox;
                });
                $offers_fba = array_values($offers_fba);
                $offers_mfn = array_values($offers_mfn);
            }

            return [
                'success' => true,
                'data' => [
                    'image' => $offersDoc['image'] ?? null,
                    'title' => $offersDoc['title'] ?? null,
                    'sku' => $offersDoc['sku'] ?? null,
                    'buybox' => $offersDoc['buybox'] ?? null,
                    'offer_buybox' => $offer_buybox,
                    'offers_fba' => $offers_fba,
                    'offers_mfn' => $offers_mfn
                ]
            ];
        }
        else {
            return [
                'success' => false,
                'message' => 'No data found'
            ];
        }
    }

    // private function savePricingOffer($userId, $shopId, $pricingHealthData, $apiResponseData)
    // {
    //     $pricingOffersCollection = $this->mongo->getCollectionForTable(self::PRICING_OFFERS_TABLE);
    //     $payload = $apiResponseData['data']['payload'] ?? [];

    //     if($payload) {
    //         $offers = [
    //             'offers'    => $payload['Offers'],
    //             'summary'   => $payload['Summary']
    //         ];
    //         $time = date('c');

    //         // Prepare data for amazon_pricing_offers
    //         $offerData = [
    //             'user_id'           => $userId,
    //             'shop_id'           => $shopId,
    //             'asin'              => $pricingHealthData['asin'],
    //             'sku'               => $payload['SKU'] ?? $pricingHealthData['sku'],
    //             'listing_id'        => $pricingHealthData['listing_id'],
    //             'seller_id'         => $pricingHealthData['seller_id'],
    //             'marketplace_id'    => $payload['marketplaceId'] ?? $pricingHealthData['marketplace_id'],
    //             'item_condition'    => $payload['Identifier']['ItemCondition'] ?? $pricingHealthData['item_condition'],
    //             'offers_info'       => $offers,
    //             'created_at'        => $time,
    //             'updated_at'        => $time
    //         ];

    //         // Insert into amazon_pricing_offers
    //         $pricingOffersCollection->insertOne($offerData);

    //         return ['success'=>true,'data'=>$offerData];
    //     }
    //     else {
    //         return ['success'=>false,'message'=>'Invalid API response'];
    //     }
    // }

    public function createDestinationForPricingNotification($userId, $shopId)
    {
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $userShop = $user_details->getShop($shopId, $userId);
        if($userShop) 
        {
            $config = $this->di->getConfig()->get('pricing_notification');
            if($config) {
                $arn = $config->get('arn')->toArray();
                if(!is_array($arn) || !isset($arn['PRICING_HEALTH']) || !isset($arn['PRICING_HEALTH'])) {
                    return ['success' => false, 'message' => 'arn not set'];
                }
            }
            else {
                return ['success' => false, 'message' => 'arn not set'];
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $destinationCollection = $mongo->getCollectionForTable('amazon_sqs_destination');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            $notifications = [
                'PRICING_HEALTH'    => [
                    'event_code' => 'PRICING_HEALTH',
                    'title'      => 'Event for Buybox',
                    'arn'        => $arn['PRICING_HEALTH']//'arn:aws:sqs:us-east-2:282133225308:amazonbyced_pricing_health'
                ], 
                'ANY_OFFER_CHANGED' => [
                    'event_code' => 'ANY_OFFER_CHANGED',
                    'title'      => 'Event for Any offer change',
                    'arn'        => $arn['ANY_OFFER_CHANGED']//'arn:aws:sqs:us-east-2:282133225308:amazonbyced_any_offer_change'
                ]
            ];

            $output = [];
            foreach ($notifications as $notification)
            {
                $destinationData = $destinationCollection->findOne(['notification_type' => $notification['event_code']], $options);
                if(!$destinationData) {
                    // Prepare specifics for sendRequestToAmazon
                    //for destination
                    $specifics = [
                        'shop_id' => $userShop['remote_shop_id'],
                        'event_data' => [
                            'marketplace_data' => [
                                'sqs' => [
                                    'arn' => $notification['arn']
                                ]
                            ],
                            'event_code' => $notification['event_code'],
                            'title' => $notification['title'],
                            'type'  => 'user',
                            'event_handler' => 'sqs'
                        ]
                    ];

                    // Use the Helper component to send the request
                    $commonHelper = $this->di->getObjectManager()->get('App\\Amazon\\Components\\Common\\Helper');
                    $response = $commonHelper->sendRequestToAmazon('create/destination', $specifics, 'POST');

                    if (isset($response['success']) && $response['success']) {
                        $destinationCollection = $mongo->getCollectionForTable('amazon_sqs_destination');
                        $insert_data = [
                            'notification_type' => $notification['event_code'],
                            'arn'               => $notification['arn'],
                            'region'            => 'all',
                            'destination_id'    => $response['destination_id']
                        ];
                        $destinationOut = $destinationCollection->insertOne($insert_data);

                        $output[$notification['event_code']] = [
                            'success' => true,
                            'message' => 'Destination created successfully',
                            'data' => $response,
                            'inserted_count' => $destinationOut->getInsertedCount()
                        ];
                    } else {
                        $output[$notification['event_code']] = [
                            'success' => false,
                            'message' => 'Destination creation Failed',
                            'data' => $response,
                        ];
                    }
                }
                else {
                    $output[$notification['event_code']] = [
                        'success' => true,
                        'message' => 'Destination already exists'
                    ];
                }
            }

            return ['success' => true, 'data' => $output];
        }
        else {
            return ['success' => false, 'message' => 'Invalid shopId'];
        }
    }

    public function subscribePricingNotification($userId, $shopId)
    {
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $userShop = $user_details->getShop($shopId, $userId);
        if($userShop) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $destinationCollection = $mongo->getCollectionForTable('amazon_sqs_destination');
            $subscriptionCollection = $mongo->getCollectionForTable('amazon_sqs_subscription');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            // Only allow PRICING_HEALTH and ANY_OFFER_CHANGED
            $notifications = [
                'PRICING_HEALTH',
                'ANY_OFFER_CHANGED'
            ];

            $output = [];
            foreach ($notifications as $notification)
            {
                $filter = [
                    'seller_id' => $userShop['warehouses'][0]['seller_id'],
                    'region'    => $userShop['warehouses'][0]['region'],
                    'topic'     => $notification
                ];
                $subscriptionData = $subscriptionCollection->findOne($filter, $options);

                if(!$subscriptionData) {
                    $destinationData = $destinationCollection->findOne(['notification_type' => $notification], $options);
                    if($destinationData) {
                        // Prepare specifics for sendRequestToAmazon
                        $specifics = [
                            'shop_id'           => $userShop['remote_shop_id'],
                            'event_code'        => $notification,
                            'destination_id'    => $destinationData['destination_id'],
                        ];

                        // Use the Helper component to send the request
                        $commonHelper = $this->di->getObjectManager()->get('App\\Amazon\\Components\\Common\\Helper');
                        $response = $commonHelper->sendRequestToAmazon('create-subscription', $specifics, 'POST');

                        if (isset($response['success']) && $response['success']) {
                            $insert_data = [
                                "user_id"       => $userId,
                                "shop_id"       => $shopId,
                                "seller_id"     => $userShop['warehouses'][0]['seller_id'],
                                "region"        => $userShop['warehouses'][0]['region'],
                                "topic"         => $notification,
                                "subscriptionId"=> $response['subscription_id'],
                                "destinationId" => $destinationData['destination_id'],
                                "payloadVersion"=> "1.0"
                            ];
                            $subscriptionOut = $subscriptionCollection->insertOne($insert_data);

                            $output[$notification] = [
                                'success' => true,
                                'message' => 'Subscription created successfully',
                                'data' => $response,
                                'inserted_count' => $subscriptionOut->getInsertedCount()
                            ];
                        }
                        else {
                            $output[$notification] = [
                                'success' => false,
                                'message' => 'Subscription creation failed',
                                'data' => $response
                            ];
                        }
                    }
                    else {
                        $output[$notification] = [
                            'success' => false, 
                            'message' => 'destination_id not found'
                        ];
                    }
                }
                else {
                    $output[$notification] = [
                        'success' => true, 
                        'message' => 'Subscription already exists'
                    ];
                }
            }

            return ['success' => true, 'data' => $output];
        }
        else {
            return ['success' => false, 'message' => 'Invalid shopId'];
        }
    }

    public function getRegionFromMarketplaceId($marketplace_id)
    {
        $region = [
            //North America
            'A2EUQ1WTGCTBG2'    => ['name' => 'North America','code' => 'NA'], // Canada
            'ATVPDKIKX0DER'     => ['name' => 'North America','code' => 'NA'], //US
            'A1AM78C64UM0Y8'    => ['name' => 'North America','code' => 'NA'], //Mexico
            'A2Q3Y263D00KWC'    => ['name' => 'North America','code' => 'NA'], //Brazil
            //Europe
            'A28R8C7NBKEWEA'    => ['name' => 'Europe','code' => 'EU'], //Ireland
            'A1RKKUPIHCS9HS'    => ['name' => 'Europe','code' => 'EU'], //Spain
            'A13V1IB3VIYZZH'    => ['name' => 'Europe','code' => 'EU'], //France
            'AMEN7PMS3EDWL'     => ['name' => 'Europe','code' => 'EU'], //Belgium
            'A1805IZSGTT6HS'    => ['name' => 'Europe','code' => 'EU'], //Netherlands
            'A1PA6795UKMFR9'    => ['name' => 'Europe','code' => 'EU'], //Germany
            'APJ6JRA9NG5V4'     => ['name' => 'Europe','code' => 'EU'], //Italy
            'A1F83G8C2ARO7P'    => ['name' => 'Europe','code' => 'EU'], //UK
            'A2NODRKZP88ZB9'    => ['name' => 'Europe','code' => 'EU'], //Sweden
            'AE08WJ6YKNBMC'     => ['name' => 'Europe','code' => 'EU'], //South Africa
            'A1C3SOZRARQ6R3'    => ['name' => 'Europe','code' => 'EU'], //Poland
            'ARBP9OOSHTCHU'     => ['name' => 'Europe','code' => 'EU'], //Egypt
            'A33AVAJ2PDY3EV'    => ['name' => 'Europe','code' => 'EU'], //Turkey
            'A17E79C6D8DWNP'    => ['name' => 'Europe','code' => 'EU'], //Soudi Arabia
            'A2VIGQ35RCS4UG'    => ['name' => 'Europe','code' => 'EU'], //UAE
            'A21TJRUUN4KGV'     => ['name' => 'Europe','code' => 'EU'], //India
            //Far East
            'A19VAU5U5O7RUS'    => ['name' => 'Far East','code' => 'FE'], //Singapore
            'A39IBJ37TRP1C6'    => ['name' => 'Far East','code' => 'FE'], //Australia
            'A1VC38T7YXB528'    => ['name' => 'Far East','code' => 'FE'], //Japan
        ];

        if(isset($region[$marketplace_id])) {
            return $region[$marketplace_id];
        }
        else {
            return false;
        }
    }

    // public function calculateBuyBoxStatus($sellerId, $featuredOfferExpectedPrice)
    // {
    //     if(isset($featuredOfferExpectedPrice['body']['featuredOfferExpectedPriceResults'])) {
    //         $result = current($featuredOfferExpectedPrice['body']['featuredOfferExpectedPriceResults']);
    //         if($result['resultStatus'] === 'VALID_FOEP' && isset($result['currentFeaturedOffer'])) {
    //             $currentFeaturedOffer = $result['currentFeaturedOffer'];
    //             if(isset($currentFeaturedOffer['offerIdentifier']['sellerId']) && $currentFeaturedOffer['offerIdentifier']['sellerId'] === $sellerId) {
    //                 return true;
    //             }
    //         }
    //     }

    //     return false;
    // }

    public function isBuyBoxFeatureAllowed($userId)
    {
        $allowedUsers = [];
        $config = $this->di->getConfig()->get('pricing_notification');
        if($config) {
            $allowedUsers = $config->get('allowed_users') ? $config->get('allowed_users')->toArray() : [];
        }
        if(!in_array($userId, $allowedUsers)) {
            throw new \Exception("buybox-access-denied", 1);
        }
    }
}