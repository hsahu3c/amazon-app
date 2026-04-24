<?php
namespace App\Amazon\Components\Buybox;

use App\Core\Models\BaseMongo;
use App\Core\Components\Base;
use App\Amazon\Components\Buybox\Buybox;
use App\Amazon\Components\Buybox\ListingOffersBatch;

class Notification extends Base
{
    public function processPricingHealth(array $sqsMessage)
    {
        try{
            if(isset($sqsMessage['notificationType']) && $sqsMessage['notificationType'] == 'PRICING_HEALTH')
            {
                if(isset($sqsMessage['payload']))
                {
                    // $mongo = new BaseMongo();
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                    $subscriptionId = $sqsMessage['notificationMetadata']['subscriptionId'];

                    $amazonSqsNotificationCollection = $mongo->getCollectionForTable('amazon_sqs_subscription');
                    $userInfo = $amazonSqsNotificationCollection->findOne(['subscriptionId' => $subscriptionId], $options);
                    if($userInfo)
                    {
                        $payload = $sqsMessage['payload'];

                        $sellerId = $payload['sellerId'] ?? null;
                        $marketplaceId = $payload['offerChangeTrigger']['marketplaceId'] ?? null;
                        $asin = $payload['offerChangeTrigger']['asin'] ?? null;

                        if($sellerId && $marketplaceId && $asin)
                        {
                            // $buyboxStatus = 'win';
                            // if(isset($payload['issueType']) && !empty($payload['issueType'])) {
                            //     $buyboxStatus = 'loss';
                            // }
                            $buyboxStatus = 'loss';

                            // $pricingHealthCollection = $mongo->getCollectionForTable(Buybox::PRICING_HEALTH_TABLE);
                            $pricingHealthCollection = $mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE);
                            // $pricingHealthItem = $pricingHealthCollection->findOne(['seller_id' => $sellerId, 'marketplace_id' => $marketplaceId, 'asin' => $asin]);
                            $pricingHealthItem = $pricingHealthCollection->findOne(['user_id' => $userInfo['user_id'], 'shop_id' => $userInfo['shop_id'], 'asin' => $asin], $options);
                            if($pricingHealthItem)
                            {
                                $updateData = [
                                    // 'pricing_health' => [
                                    //     'merchant_offer' => $payload['merchantOffer'] ?? null,
                                    //     'summary'        => $payload['summary'] ?? null
                                    // ],
                                    'time_of_offer_change'=> $payload['offerChangeTrigger']['timeOfOfferChange'] ?? null,
                                    'buybox'             => $buyboxStatus,
                                    'issue_type'         => $payload['issueType'] ?? null,
                                    'updated_at'         => date('c')
                                ];

                                // Update data in amazon_pricing_health
                                $pricingHealthCollection->updateOne(['_id' => $pricingHealthItem['_id']], ['$set' => $updateData]);

                                return ['success'=>true,'message'=>'item updated successfully'];
                            }
                            else
                            {
                                return ['success'=>true,'message'=>'item not found'];

                                // // Get amazon_listing collection and fetch listing details
                                // $listingCollection = $mongo->getCollectionForTable('amazon_listing');
                                // $listing = $listingCollection->findOne(['user_id' => $userInfo['user_id'], 'shop_id' => $userInfo['shop_id'], 'asin1' => $asin], $options);

                                // if (!$listing) {
                                //     // var_dump(json_encode(['user_id' => $userInfo['user_id'], 'shop_id' => $userInfo['shop_id'], 'asin1' => $asin]));die;
                                //     // Handle error: listing not found
                                //     return ['success'=>false,'error'=>'listing item not found'];
                                // }

                                // $pricingHealth = [
                                //     'merchant_offer' => $payload['merchantOffer'],
                                //     'summary'        => $payload['summary']
                                // ];
                                // $time = date('c');

                                // // Prepare data for amazon_pricing_health
                                // $buyboxData = [
                                //     'user_id'       => $userInfo['user_id'],
                                //     'shop_id'       => $userInfo['shop_id'],
                                //     'seller_id'     => $sellerId,
                                //     'marketplace_id'=> $marketplaceId,
                                //     'asin'          => $asin,
                                //     'image'         => $listing['image-url'] ?? null,
                                //     'sku'           => $listing['seller-sku'] ?? null,
                                //     'title'         => $listing['item-name'] ?? null,
                                //     'listing_id'    => $listing['listing-id'] ?? null,
                                //     'buybox'        => $buyboxStatus,//win | loss
                                //     'issue_type'    => $payload['issueType'] ?? null,
                                //     'item_condition'=> $payload['offerChangeTrigger']['itemCondition'] ?? null,
                                //     'time_of_offer_change'=> $payload['offerChangeTrigger']['timeOfOfferChange'] ?? null,
                                //     'pricing_health'=> $pricingHealth,
                                //     'created_at'    => $time,
                                //     'updated_at'    => $time
                                // ];

                                // // Insert into amazon_pricing_health
                                // $pricingHealthCollection->insertOne($buyboxData);

                                // return ['success'=>true,'message'=>'item inserted successfully'];
                            }
                        }
                        else {
                            return ['success'=>false,'error'=>'sellerId, marketplaceId or ASIN not found'];
                        }
                    }
                    else {
                        return ['success'=>false,'error'=>'Subscription user not found'];
                    }
                }
                else {
                    return ['success'=>false,'error'=>'Payload not found'];
                }
            }
            else {
                return ['success'=>false,'error'=>'Invalid NotificationType'];
            }
        } catch (\Exception $e) {
            return ['success'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()];
        }
    }

    public function processAnyOfferChanged(array $sqsMessage)
    {
        try {
            if(isset($sqsMessage['NotificationType']) && $sqsMessage['NotificationType'] == 'ANY_OFFER_CHANGED')
            {
                if(isset($sqsMessage['Payload']['AnyOfferChangedNotification']))
                {
                    // $mongo = new BaseMongo();
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                    $subscriptionId = $sqsMessage['NotificationMetadata']['SubscriptionId'];

                    $amazonSqsNotificationCollection = $mongo->getCollectionForTable('amazon_sqs_subscription');
                    $userInfo = $amazonSqsNotificationCollection->findOne(['subscriptionId' => $subscriptionId], $options);
                    if($userInfo)
                    {
                        $payload = $sqsMessage['Payload']['AnyOfferChangedNotification'];

                        $sellerId = $payload['SellerId'] ?? null;
                        $marketplaceId = $payload['OfferChangeTrigger']['MarketplaceId'] ?? null;
                        $asin = $payload['OfferChangeTrigger']['ASIN'] ?? null;

                        if($sellerId && $marketplaceId && $asin)
                        {
                            // $pricingOffersCollection = $mongo->getCollectionForTable(Buybox::PRICING_OFFERS_TABLE);
                            $pricingOffersCollection = $mongo->getCollectionForTable(ListingOffersBatch::LISTING_OFFERS_TABLE);
                            // $pricingOffer = $pricingOffersCollection->findOne(['seller_id' => $sellerId, 'marketplace_id' => $marketplaceId, 'asin' => $asin]);
                            $pricingOffer = $pricingOffersCollection->findOne(['user_id' => $userInfo['user_id'], 'shop_id' => $userInfo['shop_id'], 'asin' => $asin], $options);
                            if($pricingOffer)
                            {
                                // $updateData = [
                                //     'offers_info' => [
                                //         'offers'        => $payload['Offers'] ?? null,
                                //         'summary'       => $payload['Summary'] ?? null
                                //     ],
                                //     'offer_change_type' => $OfferChangeTrigger['OfferChangeType'] ?? null,
                                //     'time_of_offer_change'=> $payload['OfferChangeTrigger']['TimeOfOfferChange'] ?? null,
                                //     'updated_at'        => date('c')
                                // ];

                                $skuResponse = [
                                    'body' => [
                                        'payload' => [
                                            'Offers' => $payload['Offers'] ?? null,
                                            'Summary'=> $payload['Summary'] ?? null
                                        ]
                                    ]
                                ];
                                $isBuyboxWinner = $this->di->getObjectManager()->create(ListingOffersBatch::class)->calculateBuyBoxStatus($sellerId, $skuResponse);
                                $updateData = [
                                    'buybox'  => $isBuyboxWinner ? 'win' : 'loss',
                                    'summary' => $skuResponse['body']['payload']['Summary'] ?? null,
                                    'offers'  => $skuResponse['body']['payload']['Offers'] ?? null,
                                    'updated_at' => date('c')
                                ];

                                // Update data in amazon_pricing_offers
                                $pricingOffersCollection->updateOne(['_id' => $pricingOffer['_id']], ['$set' => $updateData]);

                                return ['success'=>true,'message'=>'item updated successfully'];
                            }
                            else
                            {
                                return ['success'=>true,'message'=>'item not found'];

                                // // Get amazon_listing collection and fetch listing details
                                // $listingCollection = $mongo->getCollectionForTable('amazon_listing');
                                // $listing = $listingCollection->findOne(['user_id' => $userInfo['user_id'], 'shop_id' => $userInfo['shop_id'], 'asin1' => $asin], $options);

                                // if (!$listing) {
                                //     // Handle error: listing not found
                                //     return ['success'=>false,'error'=>'listing item not found'];
                                // }

                                // $OfferChangeTrigger = $payload['OfferChangeTrigger'];

                                // $offers = [
                                //     'offers'    => $payload['Offers'],
                                //     'summary'   => $payload['Summary']
                                // ];
                                // $time = date('c');

                                // // Prepare data for amazon_pricing_offers
                                // $buyboxData = [
                                //     'user_id'           => $userInfo['user_id'],
                                //     'shop_id'           => $userInfo['shop_id'],
                                //     'asin'              => $asin,
                                //     'seller_id'         => $sellerId,
                                //     'marketplace_id'    => $marketplaceId,
                                //     'item_condition'    => $OfferChangeTrigger['ItemCondition'] ?? null,
                                //     'offer_change_type' => $OfferChangeTrigger['OfferChangeType'] ?? null,
                                //     'time_of_offer_change'=> $OfferChangeTrigger['TimeOfOfferChange'] ?? null,
                                //     'offers_info'       => $offers,
                                //     'created_at'        => $time,
                                //     'updated_at'        => $time
                                // ];

                                // // Insert into amazon_pricing_offers
                                // $pricingOffersCollection->insertOne($buyboxData);

                                // return ['success'=>true,'message'=>'item inserted successfully'];
                            }
                        }
                        else {
                            return ['success'=>false,'error'=>'sellerId, marketplaceId or ASIN not found'];
                        }
                    }
                    else {
                        return ['success'=>false,'error'=>'Subscription user not found'];
                    }
                }
                else {
                    return ['success'=>false,'error'=>'Payload not found'];
                }
            }
            else {
                return ['success'=>false,'error'=>'Invalid NotificationType'];
            }
        } catch (\Exception $e) {
            return ['success'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()];
        }
    }
}