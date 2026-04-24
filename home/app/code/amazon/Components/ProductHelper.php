<?php

namespace App\Amazon\Components;

use App\Amazon\Components\Template\Category;
use App\Amazon\Components\Product\Price;
use Exception;
use App\Amazon\Components\Common\Barcode;
use IteratorIterator;
use App\Amazon\Components\Product\Inventory;
use App\Amazon\Models\SourceModel;
use MongoDB\BSON\ObjectId;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;
use App\Connector\Models\ProductContainer;
use Aws\S3\S3Client;
use Aws\DynamoDb\Marshaler;
use App\Connector\Components\Dynamo;

class ProductHelper extends Base
{
      /** Constants for process-submitted queue */
      const PROCESS_SUBMITTED_BATCH_SIZE = 20;
      const PROCESS_SUBMITTED_HOURS_GAP = 12;
      const PROCESS_SUBMITTED_QUEUE_CHUNK_SIZE = 100;
  
      /**
       * Process one chunk from queue: fetch products for the chunk (by chunk_index) then process in batches.
       * When handler data is pushed with class_name => ProductHelper and method => processChunkFromQueue,
       * the message lands here.
       *
       * @param array $messageArray Full queue message (user_id, shop, chunk_index)
       * @return bool
       */
      public function processChunkFromQueue(array $messageArray)
      {
          $logFile = 'amazon/process-submitted-products/' . date('Y-m-d') . '.log';
          $userId = $messageArray['user_id'] ?? null;
          $shop = $messageArray['shop'] ?? null;
          $chunkIndex = isset($messageArray['chunk_index']) ? (int) $messageArray['chunk_index'] : null;
  
          if (!$userId || !$shop || $chunkIndex === null || $chunkIndex < 0) {
              $this->di->getLog()->logContent('processChunkFromQueue: missing user_id, shop or chunk_index', 'error', $logFile);
              return false;
          }
  
          $targetShopId = $shop['_id'] ?? null;
          $remoteShopId = $shop['remote_shop_id'] ?? null;
          if (!$targetShopId || !$remoteShopId) {
              $this->di->getLog()->logContent('processChunkFromQueue: missing target_shop_id or remote_shop_id for user ' . $userId, 'error', $logFile);
              return false;
          }
  
          $sourceShopId = null;
          if (isset($shop['sources']) && is_array($shop['sources']) && !empty($shop['sources'])) {
              $sourceShopId = $shop['sources'][0]['shop_id'] ?? null;
          }
          if (!$sourceShopId) {
              $this->di->getLog()->logContent('processChunkFromQueue: missing source_shop_id for user ' . $userId, 'error', $logFile);
              return false;
          }
  
          $twelveHoursAgo = time() - (self::PROCESS_SUBMITTED_HOURS_GAP * 3600);
          $twelveHoursAgoIso = date('c', $twelveHoursAgo);
          $skip = $chunkIndex * self::PROCESS_SUBMITTED_QUEUE_CHUNK_SIZE;
          $limit = self::PROCESS_SUBMITTED_QUEUE_CHUNK_SIZE;
  
          $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
          $productCollection = $mongo->getCollectionForTable('refine_product');
          $pipeline = $this->buildSubmittedProductsPipeline($userId, $sourceShopId, $targetShopId, $twelveHoursAgoIso, $skip, $limit);
          $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
          $products = $productCollection->aggregate($pipeline, $options)->toArray();
  
          if (empty($products)) {
              return true;
          }
  
          $batches = array_chunk($products, self::PROCESS_SUBMITTED_BATCH_SIZE);
          foreach ($batches as $batch) {
              $this->processBatch($batch, $userId, $targetShopId, $remoteShopId, $shop, null);
          }
          return true;
      }
  
      /**
       * Build aggregation pipeline for submitted products (match, unwind, match, project).
       * Used for count, for fetching products (with optional skip/limit), and for queue chunk processing.
       *
       * @param string $userId
       * @param string $sourceShopId
       * @param string $targetShopId
       * @param string $twelveHoursAgoIso
       * @param int|null $skip
       * @param int|null $limit
       * @return array
       */
      public function buildSubmittedProductsPipeline($userId, $sourceShopId, $targetShopId, $twelveHoursAgoIso, $skip = null, $limit = null)
      {
          $pipeline = [
              [
                  '$match' => [
                      'user_id' => (string) $userId,
                      'source_shop_id' => (string) $sourceShopId,
                      'target_shop_id' => (string) $targetShopId,
                  ]
              ],
              [
                  '$unwind' => [
                      'path' => '$items',
                      'preserveNullAndEmptyArrays' => false
                  ]
              ],
              [
                  '$match' => [
                      'items.target_marketplace' => 'amazon',
                      'items.status' => ['$in'=>[Helper::PRODUCT_STATUS_UPLOADED,'Uploaded']],
                  ]
              ],
              [
                  '$project' => [
                      'source_product_id' => '$items.source_product_id',
                      'container_id' => '$container_id',
                      'sku' => '$items.sku',
                      'last_synced_at' => '$items.last_synced_at',
                  ]
              ]
          ];
          if ($skip !== null) {
              $pipeline[] = [ '$skip' => $skip ];
          }
          if ($limit !== null) {
              $pipeline[] = [ '$limit' => $limit ];
          }
          return $pipeline;
      }
  
      /**
       * Process a batch of products (20 SKUs): call searchListings API and update product statuses from response.
       *
       * @param array $products
       * @param string $userId
       * @param string $targetShopId
       * @param string $remoteShopId
       * @param array $shop
       * @param \Symfony\Component\Console\Output\OutputInterface|null $output Optional CLI output (when called from command)
       * @return void
       */
      public function processBatch($products, $userId, $targetShopId, $remoteShopId, $shop, $output = null)
      {
          $logFile = 'amazon/process-submitted-products/' . date('Y-m-d') . '.log';
          $skus = [];
          $skuToProductMap = [];
  
          foreach ($products as $product) {
              $sku = $product['sku'] ?? $product['source_product_id'] ?? null;
              if ($sku) {
                  $skus[] = $sku;
                  $skuToProductMap[$sku] = $product;
              }
          }
  
          if (empty($skus)) {
              if ($output) {
                  $output->writeln('<options=bold;fg=yellow> ➤ </>No valid SKUs found in batch');
              }
              return;
          }
  
          $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
          $rawBody = [
              'sku' => $skus,
              'shop_id' => $remoteShopId,
              'includedData' => 'issues,attributes,summaries',
          ];
          $response = $commonHelper->sendRequestToAmazon('search-listing', $rawBody, 'GET');
  
          if (!isset($response['success']) || !$response['success']) {
              $errorMsg = $response['error'] ?? 'Unknown error';
              if ($output) {
                  $output->writeln('<options=bold;fg=red> ➤ </>Error calling searchListings: ' . $errorMsg);
              }
              $this->di->getLog()->logContent('Error in searchListings for user ' . $userId . ': ' . $errorMsg, 'error', $logFile);
              return;
          }
  
          $this->updateProductsFromSearchResponse($response, $skuToProductMap, $userId, $targetShopId, $shop, $output);
      }
  
      /**
       * Update product statuses based on searchListings response.
       *
       * @param array $response
       * @param array $skuToProductMap
       * @param string $userId
       * @param string $targetShopId
       * @param array $shop
       * @param \Symfony\Component\Console\Output\OutputInterface|null $output Optional CLI output (when called from command)
       * @return void
       */
      public function updateProductsFromSearchResponse($response, $skuToProductMap, $userId, $targetShopId, $shop, $output = null)
      {
          $logFile = 'amazon/process-submitted-products/' . date('Y-m-d') . '.log';
          if (!isset($response['response']['items']) || !is_array($response['response']['items'])) {
              if ($output) {
                  $output->writeln('<options=bold;fg=yellow> ➤ </>No items found in searchListings response');
              }
              return;
          }
  
          $productEditModel = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
          $saveData = [];
          $sourceShopId = null;
  
          if (isset($shop['sources']) && is_array($shop['sources']) && !empty($shop['sources'])) {
              $sourceShopId = $shop['sources'][0]['shop_id'] ?? null;
          }
  
          foreach ($response['response']['items'] as $item) {
              $itemSku = $item['sku'] ?? null;
              if (!$itemSku || !isset($skuToProductMap[$itemSku])) {
                  continue;
              }
  
              $product = $skuToProductMap[$itemSku];
              $sourceProductId = $product['source_product_id'];
              $containerId = $product['container_id'] ?? null;
              if (!$containerId) {
                  continue;
              }
  
              $amazonStatus = null;
              if (isset($item['summaries']) && is_array($item['summaries']) && !empty($item['summaries'])) {
                  if (isset($item['summaries'][0]['status'])) {
                      $statusValue = $item['summaries'][0]['status'];
                      $amazonStatus = is_array($statusValue) ? implode(',', $statusValue) : (string) $statusValue;
                  }
              }
  
              $issues = $item['issues'] ?? [];
              $hasHighPriorityIssues = false;
              $formattedErrors = [];
  
              if (!empty($issues) && is_array($issues)) {
                  foreach ($issues as $issue) {
                      $severity = strtolower($issue['severity'] ?? '');
                      if (in_array($severity, ['error', 'fatal', 'critical', 'warning'])) {
                          $hasHighPriorityIssues = true;
                          break;
                      }
                  }
                  if ($hasHighPriorityIssues) {
                      $errorHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\Error::class);
                      $formattedErrors = $errorHelper->formateErrorMessages($issues);
                  }
              }
  
              $productData = [
                  'source_product_id' => (string) $sourceProductId,
                  'container_id' => $containerId,
                  'target_marketplace' => 'amazon',
                  'shop_id' => (string) $targetShopId,
                  'user_id' => $userId,
                  'last_synced_at' => date('c'),
              ];
              if ($sourceShopId) {
                  $productData['source_shop_id'] = (string) $sourceShopId;
              }
  
              if ($hasHighPriorityIssues && !empty($formattedErrors)) {
                  if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
                      $productData['error'] = $formattedErrors['error'];
                      $productData['unset'] = ['process_tags' => 1];
                  }
                  if (isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
                      $productData['warning'] = $formattedErrors['warning'];
                  }
                  if (isset($amazonStatus) && $amazonStatus !== null) {
                      $productData['amazonStatus'] = $amazonStatus;
                  }
                  $saveData[] = $productData;
              } else {
                  $productData['status'] = Helper::PRODUCT_STATUS_UPLOADED;
                  $productData['amazonStatus'] = $amazonStatus;
                  $saveData[] = $productData;
              }
          }
  
          if (!empty($saveData)) {
              $additionalData = [
                  'source' => [
                      'marketplace' => $shop['sources'][0]['code'] ?? '',
                      'shopId' => (string) $sourceShopId
                  ],
                  'target' => [
                      'marketplace' => 'amazon',
                      'shopId' => (string) $targetShopId
                  ]
              ];
              $result = $productEditModel->saveProduct($saveData, $userId, $additionalData);
              if (isset($result['success']) && $result['success']) {
                  if ($output) {
                      $output->writeln('<options=bold;fg=green> ➤ </>Updated ' . count($saveData) . ' products');
                  }
              } else {
                  $errorMsg = $result['message'] ?? 'Unknown error';
                  if ($output) {
                      $output->writeln('<options=bold;fg=red> ➤ </>Error updating products: ' . $errorMsg);
                  }
                  $this->di->getLog()->logContent('Error updating products for user ' . $userId . ': ' . $errorMsg, 'error', $logFile);
              }
          }
    }

    public function getRating($data)
    {
        try {
            if (isset($data['source']['marketplace']) || isset($data['source']['shopId']) || isset($data['target']['marketplace']) || isset($data['target']['shopId'])) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $response = false;
                $sellerPerfcollection = $mongo->getCollectionForTable(Helper::SELLERPERFORMANCELISTING);
                $filterData = [
                    'user_id'       => $this->di->getUser()->id,
                    'shop_id'    => $data['target']['shop_id']
                ];
                $selectFields = [
                    'typeMap'   => ['root' => 'array', 'document' => 'array']
                ];
                $response = $sellerPerfcollection->find($filterData, $selectFields);
                $response = $response->toArray();
                if (!$response) {
                    return  ['success' => false, 'message' => 'no Performance Rate for this user'];
                }

                return ['success' => true, 'response' => $response];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkRefreshToken($data)
    {
        try {
            if (isset($data['source']['marketplace']) || isset($data['source']['shopId']) || isset($data['target']['marketplace']) || isset($data['target']['shopId'])) {
                if (isset($data['user_id'])) {
                    $userId = $data['user_id'];
                } else {
                    $userId = $this->di->getUser()->id;
                }

                $data['user_id'] = $userId;
                $data['group_code'] = [Helper::Feed_Status];
                $data['key'] = Helper::Account_Status;
                $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
                $res =  $configHelper->getConfigData($data);
                if (isset($res['data']) && !empty($res['data'])) {
                    foreach ($res['data'] as $item) {
                        if ($item['group_code'] == 'feedStatus' && $item['value']['accountStatus'] === 'inactive') {
                            return ['success' => true, 'message' => '<strong>We are unable to send your feed to Amazon<strong><br /><br /><p>What could you have possibly gone wrong?</p><ol><li>Your Seller Central is in vacation mode.</li><li>There might be some issue with your payment.</li></ol><p>Please make sure to review your Seller Central Account in order to avoid any interruption in syncing and uploading products to Amazon Marketplace.</p>', 'data' => $res['data']];
                        }
                        if ($item['group_code'] == 'feedStatus' && $item['value']['accountStatus'] === 'expired') {
                            return ['success' => true, 'message' => '<p>Alert! Kindly Renew SP-API Authorization Token in your Amazon Seller Account.<a href="https://bit.ly/3AfiOnK" target="_blank"> Click to view the Guide</a></p>', 'data' => $res['data']];
                        } else {
                            return ['success' => false, 'message' => 'No error in refresh token'];
                        }
                    }
                } else {
                    return ['success' => false, 'message' => 'No error in refresh token'];
                }
            } else {
                return ['success' => false, 'message' => 'Shop details not found'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function saveProduct($rawBody)
    {
        try {
            $response = [];

            if (isset($rawBody['target_marketplace'])) {
                if (isset($rawBody['edited_details'], $rawBody['container_id'])) {

                    $edited_details = $rawBody['edited_details'];
                    $containerId = $rawBody['container_id'];

                    if (isset($rawBody['variant_attributes'], $rawBody['variants'])) {

                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

                        $failed_response = [];
                        $response = ['success' => true, 'message' => 'Product saved successfully.'];

                        $resp = $this->saveProductContainer($rawBody);
                        if ($resp['success'] === false) {
                            $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                            $failed_response[] = $resp;
                        }

                        // SAVE VARIATIONS
                        $originalDataArray = [];
                        $items = $marketplaceProductCollection->find(['container_id' => (string)$containerId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                        if ($items) {
                            foreach ($items as $item) {
                                if (isset($item['group_id'])) {
                                    $originalDataArray[$item['source_product_id']] = $item;
                                }
                            }
                        }

                        $availableSourceProductIds = [];

                        foreach ($rawBody['variants'] as $variant) {
                            $availableSourceProductIds[] = $variant['source_product_id'];

                            $filterData = [
                                'source_product_id' => (string)$variant['source_product_id']
                            ];

                            $selectFields = [
                                'projection' => ['_id' =>  0],
                                'typeMap'   => ['root' => 'array', 'document' => 'array']
                            ];

                            // $originalData = $marketplaceProductCollection->findOne($filterData, $selectFields);
                            // if(!$originalData) {
                            //     $originalData = [];
                            // }

                            $originalData = $originalDataArray[$variant['source_product_id']] ?? [];
                            $editedData = [];
                            $unset = [];

                            if (array_key_exists('edit_variants', $edited_details) && $edited_details['edit_variants']) {
                                // $editedData = array_merge($editedData, $variant);
                                if (array_key_exists('is_edited_price', $variant) && $variant['is_edited_price']) {
                                    $editedData['price'] = $variant['price'];
                                } elseif (array_key_exists('price', $originalData)) {
                                    $unset['price'] = 1;
                                }

                                if (array_key_exists('is_edited_quantity', $variant) && $variant['is_edited_quantity']) {
                                    $editedData['quantity'] = $variant['quantity'];
                                } elseif (array_key_exists('quantity', $originalData)) {
                                    $unset['quantity'] = 1;
                                }

                                if (array_key_exists('is_edited_sku', $variant) && $variant['is_edited_sku']) {
                                    $editedData['sku'] = str_replace(' ', '', $variant['sku']);
                                } elseif (array_key_exists('sku', $originalData)) {
                                    $unset['sku'] = 1;
                                }

                                if (array_key_exists('is_edited_barcode', $variant) && $variant['is_edited_barcode']) {
                                    $editedData['barcode'] = $variant['barcode'];

                                    // $barcode = $this->di->getObjectManager()->create('App\Amazon\Components\Common\Barcode');
                                    // $validateBarcode = $barcode->setBarcode($variant['barcode']);
                                    // if($validateBarcode === false) {
                                    //     return ['success' => false, 'message' => "Barcode is invalid for SKU : {$variant['sku']}. Please enter valid barcode in all the variants."];
                                    // }
                                } elseif (array_key_exists('barcode', $originalData)) {
                                    $unset['barcode'] = 1;
                                }
                            }

                            if (array_key_exists('title', $edited_details)) {
                                if (is_array($edited_details['title']) && in_array('custom', $edited_details['title'])) {
                                    $editedData['title'] = $rawBody['title'];
                                } else {
                                    $unset['title'] = 1;
                                }
                            } elseif (array_key_exists('title', $originalData)) {
                                $unset['title'] = 1;
                            }

                            // if(array_key_exists('title', $edited_details) && $edited_details['title']) {
                            //     $editedData['title'] = $rawBody['title'];
                            // }
                            // elseif (array_key_exists('title', $originalData)) {
                            //     $unset['title'] = 1;
                            // }

                            if (array_key_exists('description', $edited_details)) {
                                if (is_array($edited_details['description']) && in_array('custom', $edited_details['description'])) {
                                    $editedData['description'] = $rawBody['description'];
                                } else {
                                    $unset['description'] = 1;
                                }
                            } elseif (array_key_exists('description', $originalData)) {
                                $unset['description'] = 1;
                            }

                            // if(array_key_exists('description', $edited_details) && $edited_details['description']) {
                            //     $editedData['description'] = $rawBody['description'];
                            // }
                            // elseif (array_key_exists('description', $originalData)) {
                            //     $unset['description'] = 1;
                            // }

                            if (array_key_exists('inventory_fulfillment_latency', $edited_details)) {
                                if (is_array($edited_details['inventory_fulfillment_latency']) && in_array('custom', $edited_details['inventory_fulfillment_latency'])) {
                                    $editedData['inventory_fulfillment_latency'] = $rawBody['inventory_fulfillment_latency'];
                                } else {
                                    $unset['inventory_fulfillment_latency'] = 1;
                                }
                            } elseif (array_key_exists('inventory_fulfillment_latency', $originalData)) {
                                $unset['inventory_fulfillment_latency'] = 1;
                            }

                            // if(array_key_exists('inventory_fulfillment_latency', $edited_details) && $edited_details['inventory_fulfillment_latency']) {
                            //     $editedData['inventory_fulfillment_latency'] = $rawBody['inventory_fulfillment_latency'];
                            // }
                            // elseif (array_key_exists('inventory_fulfillment_latency', $originalData)) {
                            //     $unset['inventory_fulfillment_latency'] = 1;
                            // }

                            // if(array_key_exists('parent_sku', $edited_details) && $edited_details['parent_sku']) {
                            //     $editedData['parent_sku'] = $rawBody['parent_sku'];
                            // }
                            // elseif (array_key_exists('parent_sku', $originalData)) {
                            //     $unset['parent_sku'] = 1;
                            // }

                            if ((!empty($rawBody['amazon_seller_id']) || isset($edited_details['amazon_seller_ids_array']))) {
                                $cat_data = [];

                                $optional_attribute_field_name = 'optional_fields';
                                if (isset($variant[$optional_attribute_field_name])) {
                                    // $cat_data['optional_attributes'] = array_filter($variant[$optional_attribute_field_name]);
                                    $cat_data['optional_attributes'] = $variant[$optional_attribute_field_name];
                                }

                                if (isset($rawBody['variation_theme_value'])) {
                                    $cat_data['variation_theme_attribute_name'] = $rawBody['variation_theme_value'];
                                }

                                if (isset($variant['variation_theme_fields'])) {
                                    $cat_data['variation_theme_attribute_value'] = $variant['variation_theme_fields'];
                                }

                                if (array_key_exists('assign_category', $edited_details) && is_array($edited_details['assign_category']) && in_array('custom', $edited_details['assign_category'])) {
                                    $cat_data['category_settings'] = $rawBody['category_settings'];
                                } else {
                                    $cat_data['category_settings'] = ['barcode_exemption' => $rawBody['category_settings']['barcode_exemption'] ?? false];
                                }

                                if (!empty($cat_data)) {
                                    if (isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                                        foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                                            $editedData['shops'][$amazonSellerId['id']] = $cat_data;
                                        }
                                    } else {
                                        $editedData['shops'][$rawBody['amazon_seller_id']] = $cat_data;
                                    }
                                } else {
                                    $unset['shops'] = 1;
                                }
                            }

                            // if((!empty($rawBody['amazon_seller_id']) || isset($edited_details['amazon_seller_ids_array'])) && array_key_exists('assign_category', $edited_details))
                            // {
                            //     if($edited_details['assign_category'])
                            //     {
                            //         if(isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                            //             foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                            //                 $editedData['shops'][$amazonSellerId['id']] = ['category_settings' => $rawBody['category_settings']];
                            //             }
                            //         } else {
                            //             $editedData['shops'][$rawBody['amazon_seller_id']] = ['category_settings' => $rawBody['category_settings']];
                            //         }
                            //     }
                            //     else
                            //     {
                            //         if(isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                            //             // foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                            //             //     if(isset($originalData['shops']) && array_key_exists($rawBody['amazon_seller_id'], $originalData['shops'])) {
                            //             //         $unset["shops.{$amazonSellerId['id']}"] = 1;
                            //             //     }
                            //             // }
                            //             $unset['shops'] = 1;
                            //         } else {
                            //             // if(isset($originalData['shops']) && array_key_exists($rawBody['amazon_seller_id'], $originalData['shops'])) {
                            //             //     $unset["shops.{$rawBody['amazon_seller_id']}"] = 1;
                            //             // }
                            //             $unset['shops'] = 1;
                            //         }
                            //     }
                            // }

                            if (count($rawBody['variant_attributes']) > 0) {
                                if (!isset($originalData['group_id'])) {
                                    $editedData['group_id'] = $containerId;
                                }
                            } elseif (isset($originalData['group_id'])) {
                                $unset['group_id'] = 1;
                            }

                            if (!empty($unset)) {
                                $marketplaceProductCollection->updateOne($filterData, ['$unset' => $unset]);
                            }

                            if (!empty($editedData)) {
                                $editedData['user_id'] = $this->di->getUser()->id;
                                $editedData['container_id'] = $containerId;

                                $product = [
                                    'target_marketplace' => $rawBody['target_marketplace'],
                                    'source_product_id'  => $variant['source_product_id'],
                                    'edited'             => $editedData
                                ];

                                $products = new ProductContainer();
                                $resp = $products->editedProduct($product);

                                if ($resp['success'] === false) {
                                    $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                                    $failed_response[] = $resp;
                                }
                            }
                        }

                        $deleteSourceProIds = [];
                        foreach ($originalDataArray as $sourceProductId => $value) {
                            if (!in_array($sourceProductId, $availableSourceProductIds)) {
                                $deleteSourceProIds[] = (string)$sourceProductId;
                            }
                        }

                        if (!empty($deleteSourceProIds)) {
                            $marketplaceProductCollection->deleteMany([
                                'user_id' => $this->di->getUser()->id,
                                'source_product_id' => ['$in' => $deleteSourceProIds]
                            ], ['w' => true]);
                        }

                        // SAVE VARIATIONS

                        if (!empty($failed_response)) {
                            $response['failed'] = $failed_response;
                        }

                        $validate = $this->validateAmazonProduct($rawBody);
                        if ($validate['success'] === false) {
                            return $validate;
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'variant_attributes & variants is required.'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'edited_details & container_id is required.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'target_marketplace is required.'];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function saveProductContainer($rawBody)
    {
        $edited_details = $rawBody['edited_details'];
        $containerId = $rawBody['container_id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

        $originalContainerData = $marketplaceProductCollection->findOne(['source_product_id' => (string)$containerId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        if (!$originalContainerData) {
            $originalContainerData = [];
        }

        // if(count($rawBody['variants']) > 1)
        if (count($rawBody['variant_attributes']) > 0) {
            $containerData = [];
            $containerUnset = [];
            if (array_key_exists('title', $edited_details)) {
                if (is_array($edited_details['title']) && in_array('custom', $edited_details['title'])) {
                    $containerData['title'] = $rawBody['title'];
                } else {
                    $containerUnset['title'] = 1;
                }
            } elseif (array_key_exists('title', $originalContainerData)) {
                $containerUnset['title'] = 1;
            }

            if (array_key_exists('description', $edited_details)) {
                if (is_array($edited_details['description']) && in_array('custom', $edited_details['description'])) {
                    $containerData['description'] = $rawBody['description'];
                } else {
                    $containerUnset['description'] = 1;
                }
            } elseif (array_key_exists('description', $originalContainerData)) {
                $containerUnset['description'] = 1;
            }

            if (array_key_exists('parent_sku', $edited_details) && $edited_details['parent_sku']) {
                $containerData['parent_sku'] = str_replace(' ', '', $rawBody['parent_sku']);
            } elseif (array_key_exists('parent_sku', $originalContainerData)) {
                $containerUnset['parent_sku'] = 1;
            }

            if ((!empty($rawBody['amazon_seller_id']) || isset($edited_details['amazon_seller_ids_array']))) {
                $cat_data = [];

                if (isset($rawBody['variation_theme_value'])) {
                    $cat_data['variation_theme_attribute_name'] = $rawBody['variation_theme_value'];
                }

                if (array_key_exists('assign_category', $edited_details) && is_array($edited_details['assign_category']) && in_array('custom', $edited_details['assign_category'])) {
                    $cat_data['category_settings'] = $rawBody['category_settings'];
                    unset($cat_data['category_settings']['attributes_mapping']);
                } else {
                    $cat_data['category_settings'] = ['barcode_exemption' => $rawBody['category_settings']['barcode_exemption'] ?? false];
                }

                if (!empty($cat_data)) {
                    if (isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                        foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                            $containerData['shops'][$amazonSellerId['id']] = $cat_data;
                        }
                    } else {
                        $containerData['shops'][$rawBody['amazon_seller_id']] = $cat_data;
                    }
                } else {
                    $containerUnset['shops'] = 1;
                }
            }

            if (!empty($containerUnset)) {
                $marketplaceProductCollection->updateOne(['source_product_id' => (string)$containerId], ['$unset' => $containerUnset]);
            }

            if (!empty($containerData)) {
                $containerData['user_id'] = $this->di->getUser()->id;
                $containerData['container_id'] = $containerId;

                $product = [
                    'target_marketplace' => $rawBody['target_marketplace'],
                    'source_product_id'  => $containerId,
                    'edited'             => $containerData
                ];

                $products = new ProductContainer();
                return $products->editedProduct($product);
            }
        } else {
            if (!empty($originalContainerData)) {
                $response = $marketplaceProductCollection->deleteOne(['source_product_id' => (string)$containerId], ['w' => true]);

                if ($response->isAcknowledged()) {
                    return ['success' => true];
                }
                return ['success' => false];
            }
        }

        return ['success' => true];
    }

    public function validateAmazonProduct($rawBody)
    {
        $validate = [];

        // print_r($rawBody);die;
        if (isset($rawBody['target_marketplace'])) {
            if (isset($rawBody['edited_details'], $rawBody['container_id'])) {

                $edited_details = $rawBody['edited_details'];
                $containerId = $rawBody['container_id'];

                // Validate title
                if (array_key_exists('title', $edited_details)) {
                    if (is_array($edited_details['title']) && in_array('custom', $edited_details['title'])) {
                        if (empty($rawBody['title'])) {
                            $validate['title'] = 'title can not be empty';

                            return ['success' => false, 'message' => "title can not be empty"];
                        }
                    } else {
                        // Validate Shopify's default title
                    }
                }

                // Validate description
                if (array_key_exists('description', $edited_details)) {
                    if (is_array($edited_details['description']) && in_array('custom', $edited_details['description'])) {
                        if (empty($rawBody['description'])) {
                            $validate['description'] = 'description can not be empty';

                            return ['success' => false, 'message' => "description can not be empty"];
                        }
                    } else {
                        // Validate Shopify's default description
                    }
                }

                if (count($rawBody['variant_attributes']) > 0) {
                    // Validate Parent SKU
                    if (array_key_exists('parent_sku', $edited_details)) {
                        if (empty($rawBody['parent_sku'])) {
                            $validate['parent_sku'] = 'parent_sku can not be empty';

                            return ['success' => false, 'message' => "parent_sku can not be empty"];
                        }
                    }
                }

                // Validate inventory_fulfillment_latency
                if (array_key_exists('inventory_fulfillment_latency', $edited_details)) {
                    if (is_array($edited_details['inventory_fulfillment_latency']) && in_array('custom', $edited_details['inventory_fulfillment_latency'])) {
                        if ($rawBody['inventory_fulfillment_latency'] < 1 || $rawBody['inventory_fulfillment_latency'] > 30) {
                            $validate['inventory_fulfillment_latency'] = 'Handling Time must be numeric and between 1 to 30';

                            return ['success' => false, 'message' => "Handling Time must be numeric and between 1 to 30"];
                        }
                    } else {
                        //validate template inventory_fulfillment_latency data.
                        if (array_key_exists('profile_data', $rawBody)) {

                            $profileData = $rawBody['profile_data'];
                            if (array_key_exists('inventory', $profileData)) {
                                $inventoryData = $profileData['inventory'];

                                if (empty($inventoryData['inventory_fulfillment_latency'])) {
                                    return ['success' => false, 'message' => "Handling Time is not assigned in this product. Please assign it by choosing 'Set custom' option from 'Handling Time'"];
                                }
                            }
                        }
                    }
                }

                // Validate category
                $isCategoryAssigned = false;
                $primaryCategory = '';
                $attributesMapping = [];
                $categorySettings = [];
                if (array_key_exists('assign_category', $edited_details) && is_array($edited_details['assign_category'])) {
                    $categorySettings = $rawBody['category_settings'];
                    if (in_array('custom', $edited_details['assign_category'])) {
                        $attributesMapping = $categorySettings['attributes_mapping'];
                        if (empty($categorySettings['primary_category'])) {
                            $validate['primary_category'] = 'primary_category is required';
                            return ['success' => false, 'message' => "primary_category is required"];
                        }
                        if (empty($categorySettings['sub_category'])) {
                            $validate['sub_category'] = 'sub_category is required';
                            return ['success' => false, 'message' => "sub_category is required"];
                        }

                        if ($categorySettings['browser_node_id'] != '0' && (empty($categorySettings['browser_node_id']) || !is_numeric($categorySettings['browser_node_id']))) {
                            $validate['browser_node_id'] = 'browser_node_id can not be empty and must be numeric.';
                            return ['success' => false, 'message' => "browser_node_id can not be empty and must be numeric."];
                        } else {
                            $isCategoryAssigned = true;
                            $primaryCategory = $categorySettings['primary_category'];
                        }
                    } else {
                        // validate template category
                        if (array_key_exists('profile_data', $rawBody)) {

                            $profileData = $rawBody['profile_data'];
                            if (array_key_exists('category', $profileData)) {
                                $categoryData = $profileData['category'];

                                if (empty($categoryData['primary_category'])) {
                                    return ['success' => false, 'message' => "Amazon Category not assigned. Go to “Add New Category” and select “Add Category to Products” to assign an Amazon category."];
                                }
                                $isCategoryAssigned = true;
                                $primaryCategory = $categoryData['primary_category'];
                            }
                        }
                    }
                }

                $barcodeExemption = $categorySettings['barcode_exemption']  ?? false;

                foreach ($rawBody['variants'] as $variant) {
                    if (array_key_exists('edit_variants', $edited_details) && $edited_details['edit_variants']) {

                        if (array_key_exists('is_edited_barcode', $variant) && $variant['is_edited_barcode']) {

                            if (!$barcodeExemption || $primaryCategory == 'default') {

                                $barcode = $this->di->getObjectManager()->create(Barcode::class);
                                $validateBarcode = $barcode->setBarcode($variant['barcode']);
                                if ($validateBarcode === false) {
                                    $validate['barcode'][$variant['sku']] = "Barcode is invalid for SKU : {$variant['sku']}. Please enter valid barcode in all the variants.";

                                    return ['success' => false, 'message' => "Barcode is invalid for SKU : {$variant['sku']}. Please enter valid barcode in all the variants."];
                                }
                            }
                        }
                    }
                }
            }
        }

        // print_r($validate);die;

        if (!empty($validate)) {
            return ['success' => false, 'message' => 'product validation failed.', 'errors' => $validate];
        }
        return ['success' => true];
    }

    private function getAmazonAttributes($primaryCategory, $subCategory, $browserNodeId, $barcodeExemption)
    {
        $userId = $this->di->getUser()->id;
        $shops = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->findShop(['marketplace' => 'amazon'], $userId);
        if (empty($shops)) {
            return false;
        }
        $shop = current($shops);
        $homeShopId = $shop['_id'];
        // $remoteShopId = $shop['remote_shop_id'];
        // shop_id=86&category=bookloader&sub_category=bookloader
        $request_data = [
            'shop_id'           => $homeShopId,
            'category'          => $primaryCategory,
            'sub_category'      => $subCategory,
            'browser_node_id'    => $browserNodeId,
            'barcode_exemption'    => $barcodeExemption
        ];
        return $this->di->getObjectManager()->get(Category::class)->init($request_data)->getAmazonAttributes($request_data);
    }

    public function getEditedProduct($rawBody)
    {
        try {
            if (empty($rawBody['target_marketplace'])) {
                return ['success' => false, 'message' => 'target_marketplace is required.'];
            }
            if (isset($rawBody['source_product_ids'])) {
                if (is_array($rawBody['source_product_ids'])) {
                    $ids = [];
                    foreach ($rawBody['source_product_ids'] as $id) {
                        $ids[] = (string)$id;
                    }

                    $filterData = [
                        'source_product_id' => ['$in' => $ids]
                    ];

                    $selectFields = [
                        'projection' => ['_id' =>  0],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
                    $res = $collection->find($filterData, $selectFields);
                    $res = $res->toArray();

                    $settings = $this->getProductSettings($this->di->getUser()->id, $res, ['category', 'inventory']);

                    $productData = [];
                    foreach ($res as $value) {
                        if (isset($settings[$value['container_id']])) {
                            $value['profile_data'] = $settings[$value['container_id']];

                            if (!isset($value['profile_id'])) {
                                $value['profile_id'] = $settings[$value['container_id']]['profile_id'] ?? 'default';
                                $value['profile_name'] = $settings[$value['container_id']]['profile_name'] ?? 'default';
                            }
                        }

                        $productData[] = $value;
                    }

                    $responseData = [
                        'success' => true,
                        'data' => $productData
                    ];

                    return $responseData;
                }
                return ['success' => false, 'message' => 'source_product_ids must be array'];
            }
            if (isset($rawBody['container_id'])) {
                if (is_numeric($rawBody['container_id'])) {
                    $filterData = [
                        'container_id' => (string)$rawBody['container_id']
                    ];

                    $selectFields = [
                        'projection' => ['_id' =>  0],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
                    $res = $collection->find($filterData, $selectFields);
                    $res = $res->toArray();


                    $settings = $this->getProductSettings($this->di->getUser()->id, $res, ['category', 'inventory']);

                    $productData = [];
                    foreach ($res as $value) {

                        if (isset($settings[$value['container_id']])) {
                            $value['profile_data'] = $settings[$value['container_id']];

                            if (!isset($value['profile_id'])) {
                                $value['profile_id'] = $settings[$value['container_id']]['profile_id'] ?? 'default';
                                $value['profile_name'] = $settings[$value['container_id']]['profile_id'] ?? 'default';
                            }
                        }

                        $productData[] = $value;
                    }

                    $responseData = [
                        'success' => true,
                        'data' => $productData
                    ];

                    return $responseData;
                }
                return ['success' => false, 'message' => 'container_id must be numeric'];
            }
            if (isset($rawBody['container_ids'])) {
                if (is_array($rawBody['container_ids'])) {
                    $ids = [];
                    foreach ($rawBody['container_ids'] as $id) {
                        $ids[] = (string)$id;
                    }

                    $filterData = [
                        'container_id' => ['$in' => $ids]
                    ];

                    $selectFields = [
                        'projection' => ['_id' =>  0],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
                    $res = $collection->find($filterData, $selectFields);
                    $res = $res->toArray();

                    $settings = $this->getProductSettings($this->di->getUser()->id, $res, ['category', 'inventory']);

                    $productData = [];
                    foreach ($res as $value) {
                        if (isset($settings[$value['container_id']])) {
                            $value['profile_data'] = $settings[$value['container_id']];

                            if (!isset($value['profile_id'])) {
                                $value['profile_id'] = $settings[$value['container_id']]['profile_id'] ?? 'default';
                                $value['profile_name'] = $settings[$value['container_id']]['profile_id'] ?? 'default';
                            }
                        }

                        $productData[$value['container_id']][] = $value;
                    }

                    $responseData = [
                        'success' => true,
                        'data' => $productData
                    ];

                    return $responseData;
                }
                return ['success' => false, 'message' => 'container_ids must be array.'];
            } else {
                return ['success' => false, 'message' => 'either source_product_ids or container_id are required.'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function formatEditedProduct($editedProduct)
    {
        if (count($editedProduct) > 1) {
            $formatted_product = [];

            foreach ($editedProduct as $product) {
                if (!isset($product['container_id'], $product['source_product_id'])) {
                    return $editedProduct;
                }
                if (array_key_exists('group_id', $product)) {
                    $sourceProductId = $product['source_product_id'];

                    $formatted_product['variants'][$sourceProductId] = $product;
                } else {
                    if (isset($formatted_product['variants'])) {
                        $formatted_product = array_merge($formatted_product, $product);
                    } else {
                        $formatted_product = $product;
                    }
                }
            }

            return $formatted_product;
        }
        return $editedProduct;
    }

    /**
     * @return non-empty-array[]
     */
    private function getProfileDataFromId($profile_ids = [], $template_types = []): array
    {
        $prepareProfileData = [];

        if (!empty($profile_ids)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::PROFILES);

            $filterData = [
                'user_id'       => $this->di->getUser()->id,
                'profile_id'    => ['$in' => $profile_ids]
            ];

            $selectFields = [
                'projection' => ['_id' => 0, 'profile_id' => 1, 'settings' => 1],
                'typeMap'   => ['root' => 'array', 'document' => 'array']
            ];
            $profiles_data = $collection->find($filterData, $selectFields);

            $allowed_template_types = ['category', 'inventory', 'product', 'pricing'];

            $template_ids = ['all' => [], 'category' => [], 'inventory' => [], 'product' => [], 'pricing' => []];

            foreach ($profiles_data as $profile_data) {
                /*"settings" : {
                    "amazon" : {
                            "8" : {
                                    "templates" : {
                                            "category" : "81",
                                            "inventory" : "83",
                                            "product" : "84",
                                            "pricing" : "82"
                                    }
                            }
                    }
                },*/
                $templates = current($profile_data['settings']['amazon']);

                foreach ($allowed_template_types as $name) {
                    if (in_array($name, $template_types)) {
                        $templateId = $templates['templates'][$name] ?? false;
                        if ($templateId) {
                            if (!array_key_exists($templateId, $template_ids[$name])) {
                                $template_ids[$name][(int)$templateId] = $profile_data['profile_id'];
                            }

                            if (!in_array($templateId, $template_ids['all'])) {
                                $template_ids['all'][] = (int)$templateId;
                            }
                        }
                    }
                }
            }

            if (!empty($template_ids['all'])) {
                $collection = $mongo->getCollectionForTable(Helper::PROFILE_SETTINGS);

                $filterData = [
                    '_id'   => ['$in' => $template_ids['all']]
                ];

                $selectFields = [
                    'projection' => ['_id' => 1, 'data' => 1, 'type' => 1],
                    'typeMap'   => ['root' => 'array', 'document' => 'array']
                ];

                $templates_data = $collection->find($filterData, $selectFields);

                foreach ($templates_data as $template) {
                    if (!isset($template['data']['settings_enabled']) || (isset($template['data']['settings_enabled']) && $template['data']['settings_enabled'] === true)) {
                        $profileId = $template_ids[$template['type']][$template['_id']];
                        $prepareProfileData[$profileId][$template['type']] = $template['data'] ?? $template;
                    }
                }
            }
        }

        return $prepareProfileData;
    }

    public function validateProductsForBulkEdit($rawBody)
    {
        try {
            if (isset($rawBody['source_product_ids'])) {
                if (is_array($rawBody['source_product_ids'])) {
                    $categorised_products = [];

                    $ids = [];
                    foreach ($rawBody['source_product_ids'] as $id) {
                        $ids[] = (string)$id;
                    }

                    $profiled_products = ['not-profiled' => $ids];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

                    $filterData = [
                        // 'user_id'       => $this->di->getUser()->id,
                        'source_product_id' => ['$in' => $ids],
                        // 'group_id'          => ['$exists' => 0],
                        'shops'             => ['$exists' => 1]
                    ];

                    $selectFields = [
                        'projection' => ['container_id' => 1, 'source_product_id' => 1, 'shops' => 1],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $res_arr = $collection->find($filterData, $selectFields);

                    foreach ($res_arr as $res) {
                        $shop_data = current($res['shops']);

                        $category = $shop_data['category_settings'];
                        if (isset($category['attributes_mapping'])) {
                            unset($category['attributes_mapping']);
                        }

                        if (!empty($category['primary_category'])) {
                            if (!array_key_exists('barcode_exemption', $category)) {
                                $category['barcode_exemption'] = false;
                            }

                            $cat_id = "{$category['primary_category']}~{$category['sub_category']}~{$category['browser_node_id']}";

                            $cat_id = $category['barcode_exemption'] ? "{$cat_id}-barcode_exempted" : "{$cat_id}-barcode_not_exempted";

                            if (!isset($categorised_products[$cat_id])) {
                                $categorised_products[$cat_id] = [
                                    'container_ids' => [$res['container_id']],
                                    'category'      => $category
                                ];
                            } else {
                                $categorised_products[$cat_id]['container_ids'][] = $res['container_id'];
                            }

                            if (isset($profiled_products['not-profiled'])) {
                                $index = array_search($res['container_id'], $profiled_products['not-profiled']);
                                if ($index !== false) {
                                    unset($profiled_products['not-profiled'][$index]);
                                }
                            }
                        }
                    }

                    if (!empty($profiled_products['not-profiled'])) {
                        $profile_ids = [];

                        $profiled_products['not-profiled'] = array_values($profiled_products['not-profiled']);

                        $filterData = [
                            'user_id'           => $this->di->getUser()->id,
                            'source_product_id' => ['$in' => $profiled_products['not-profiled']],
                            // 'group_id'          => ['$exists' => 0],
                            'profile'           => ['$exists' => 1]
                        ];

                        $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

                        $selectFields = [
                            'projection' => ['container_id' => 1, 'source_product_id' => 1, 'profile' => 1],
                            'typeMap'   => ['root' => 'array', 'document' => 'array']
                        ];

                        $res_arr = $collection->find($filterData, $selectFields);

                        foreach ($res_arr as $res) {
                            if (isset($res['profile']) && !empty($res['profile'])) {

                                foreach ($res['profile'] as $profile) {
                                    if (isset($profile['app_tag']) && $profile['app_tag'] == 'amazon') {

                                        if (!isset($profiled_products[$profile['profile_id']])) {
                                            $profiled_products[$profile['profile_id']][] = $res['container_id'];
                                        } else {
                                            if (!in_array($res['container_id'], $profiled_products[$profile['profile_id']])) {
                                                $profiled_products[$profile['profile_id']][] = $res['container_id'];
                                            }
                                        }

                                        if (!in_array($profile['profile_id'], $profile_ids)) {
                                            $profile_ids[] = (string)$profile['profile_id'];
                                        }

                                        if (isset($profiled_products['not-profiled'])) {
                                            $index = array_search($res['container_id'], $profiled_products['not-profiled']);
                                            if ($index !== false) {
                                                unset($profiled_products['not-profiled'][$index]);
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $profiles_data = $this->getProfileDataFromId($profile_ids, ['category']);
                        if ($profiles_data) {
                            foreach ($profiles_data as $profileId => $profile_data) {
                                if (isset($profile_data['category'])) {
                                    $category = $profile_data['category'];
                                    unset($category['attributes_mapping']);

                                    $cat_id = "{$category['primary_category']}~{$category['sub_category']}~{$category['browser_node_id']}";

                                    $cat_id = $category['barcode_exemption'] ? "{$cat_id}-barcode_exempted" : "{$cat_id}-barcode_not_exempted";

                                    if (!isset($categorised_products[$cat_id])) {
                                        $categorised_products[$cat_id] = [
                                            'container_ids' => $profiled_products[$profileId],
                                            'category'      => $category
                                        ];
                                    } else {
                                        $categorised_products[$cat_id]['container_ids'] = [...$categorised_products[$cat_id]['container_ids'], ...$profiled_products[$profileId]];
                                    }
                                }
                            }
                        }
                    }

                    $uncategorised_products = [];
                    if (isset($profiled_products['not-profiled']) && count($profiled_products['not-profiled'])) {
                        $uncategorised_products = array_values($profiled_products['not-profiled']);
                    }

                    if (!empty($categorised_products)) {
                        $this->setBrowseNodeNameFromId($categorised_products);

                        if (empty($uncategorised_products)) {
                            $response = [
                                'success'                   => true,
                                'categorised_container_ids' => array_values($categorised_products)
                            ];
                        } else {
                            $response = [
                                'success'                       => true,
                                'categorised_container_ids'     => array_values($categorised_products),
                                'uncategorised_container_ids'   => $uncategorised_products
                            ];
                        }
                    } else {
                        $response = [
                            'success'   => false,
                            'message'   => 'all selected product(s) are not categorised and not allowed for bulk edit'
                        ];
                    }

                    return $response;
                }
                return ['success' => false, 'message' => 'source_product_ids must be array'];
            }
            if (isset($rawBody['container_ids'])) {
                if (is_array($rawBody['container_ids'])) {
                    $categorised_products = [];

                    $ids = [];
                    foreach ($rawBody['container_ids'] as $id) {
                        $ids[] = (string)$id;
                    }

                    $profiled_products = ['not-profiled' => $ids];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

                    $filterData = [
                        // 'user_id'       => $this->di->getUser()->id,
                        'container_id'  => ['$in' => $ids],
                        // 'group_id'      => ['$exists' => 0],
                        'shops'         => ['$exists' => 1]
                    ];

                    $selectFields = [
                        'projection' => ['container_id' => 1, 'source_product_id' => 1, 'shops' => 1],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $res_arr = $collection->find($filterData, $selectFields);

                    foreach ($res_arr as $res) {
                        /*"shops" : {
                            "86" : {
                                    "category_settings" : {
                                            "primary_category" : "jewelry",
                                            "sub_category" : "finering",
                                            "browser_node_id" : "3732783031",
                                            "barcode_exemption" : true
                                    }
                            }
                        }*/
                        $shop_data = current($res['shops']);

                        $category = $shop_data['category_settings'];
                        if (isset($category['attributes_mapping'])) {
                            unset($category['attributes_mapping']);
                        }

                        if (!empty($category['primary_category'])) {
                            if (!array_key_exists('barcode_exemption', $category)) {
                                $category['barcode_exemption'] = false;
                            }

                            $cat_id = "{$category['primary_category']}~{$category['sub_category']}~{$category['browser_node_id']}";

                            $cat_id = $category['barcode_exemption'] ? "{$cat_id}-barcode_exempted" : "{$cat_id}-barcode_not_exempted";

                            if (!isset($categorised_products[$cat_id])) {
                                $categorised_products[$cat_id] = [
                                    'container_ids' => [$res['container_id']],
                                    'category'      => $category
                                ];
                            } else {
                                $categorised_products[$cat_id]['container_ids'][] = $res['container_id'];
                            }

                            if (isset($profiled_products['not-profiled'])) {
                                $index = array_search($res['container_id'], $profiled_products['not-profiled']);
                                if ($index !== false) {
                                    unset($profiled_products['not-profiled'][$index]);
                                }
                            }
                        }
                    }

                    if (!empty($profiled_products['not-profiled'])) {
                        $profile_ids = [];

                        $profiled_products['not-profiled'] = array_values($profiled_products['not-profiled']);

                        $filterData = [
                            'user_id'           => $this->di->getUser()->id,
                            // 'container_id'      => ['$in' => $ids],
                            'container_id'      => ['$in' => $profiled_products['not-profiled']],
                            // 'group_id'          => ['$exists' => 0],
                            'profile'           => ['$exists' => 1]
                        ];

                        $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

                        $selectFields = [
                            'projection' => ['container_id' => 1, 'source_product_id' => 1, 'profile' => 1],
                            'typeMap'   => ['root' => 'array', 'document' => 'array']
                        ];

                        $res_arr = $collection->find($filterData, $selectFields);

                        foreach ($res_arr as $res) {
                            if (isset($res['profile']) && !empty($res['profile'])) {
                                /*"profile":[
                                    {
                                        "profile_id":"1",
                                        "profile_name":"Profile Name",
                                        "app_tag":"amazon"
                                    }
                                ]*/
                                foreach ($res['profile'] as $profile) {
                                    if (isset($profile['app_tag']) && $profile['app_tag'] == 'amazon') {

                                        if (!isset($profiled_products[$profile['profile_id']])) {
                                            $profiled_products[$profile['profile_id']][] = $res['container_id'];
                                        } else {
                                            if (!in_array($res['container_id'], $profiled_products[$profile['profile_id']])) {
                                                $profiled_products[$profile['profile_id']][] = $res['container_id'];
                                            }
                                        }

                                        if (!in_array($profile['profile_id'], $profile_ids)) {
                                            $profile_ids[] = (string)$profile['profile_id'];
                                        }

                                        if (isset($profiled_products['not-profiled'])) {
                                            $index = array_search($res['container_id'], $profiled_products['not-profiled']);
                                            if ($index !== false) {
                                                unset($profiled_products['not-profiled'][$index]);
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $profiles_data = $this->getProfileDataFromId($profile_ids, ['category']);
                        if ($profiles_data) {
                            foreach ($profiles_data as $profileId => $profile_data) {
                                if (isset($profile_data['category'])) {
                                    $category = $profile_data['category'];
                                    if (isset($category['attributes_mapping'])) {
                                        unset($category['attributes_mapping']);
                                    }

                                    $cat_id = "{$category['primary_category']}~{$category['sub_category']}~{$category['browser_node_id']}";

                                    $cat_id = $category['barcode_exemption'] ? "{$cat_id}-barcode_exempted" : "{$cat_id}-barcode_not_exempted";

                                    if (!isset($categorised_products[$cat_id])) {
                                        $categorised_products[$cat_id] = [
                                            'container_ids' => $profiled_products[$profileId],
                                            'category'      => $category
                                        ];
                                    } else {
                                        $categorised_products[$cat_id]['container_ids'] = [...$categorised_products[$cat_id]['container_ids'], ...$profiled_products[$profileId]];
                                    }
                                }
                            }
                        }
                    }

                    $uncategorised_products = [];
                    if (isset($profiled_products['not-profiled']) && count($profiled_products['not-profiled'])) {
                        $uncategorised_products = array_values($profiled_products['not-profiled']);
                    }

                    if (!empty($categorised_products)) {
                        $this->setBrowseNodeNameFromId($categorised_products);

                        if (empty($uncategorised_products)) {
                            $response = [
                                'success'                   => true,
                                'categorised_container_ids' => array_values($categorised_products)
                            ];
                        } else {
                            $response = [
                                'success'                       => true,
                                'categorised_container_ids'     => array_values($categorised_products),
                                'uncategorised_container_ids'   => $uncategorised_products
                            ];
                        }
                    } else {
                        $response = [
                            'success'   => false,
                            'message'   => 'all selected product(s) are not categorised and not allowed for bulk edit'
                        ];
                    }

                    return $response;
                }
                return ['success' => false, 'message' => 'container_ids must be array'];
            } else {
                return ['success' => false, 'message' => 'either source_product_ids or container_ids are required.'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage() . PHP_EOL . $e->getTraceAsString()];
        }
    }

    private function setBrowseNodeNameFromId(&$categorisedProducts)
    {
        $userId = $this->di->getUser()->id;
        $shops = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->findShop(['marketplace' => 'amazon'], $userId);
        if (empty($shops)) {
            return false;
        }
        $shop = current($shops);
        $homeShopId = $shop['_id'];
        // $remoteShopId = $shop['remote_shop_id'];
        // shop_id=86&category=bookloader&sub_category=bookloader
        foreach ($categorisedProducts as $key => $categorisedProduct) {
            if (isset($categorisedProduct['category'])) {
                $categoryData = $categorisedProduct['category'];

                $request_data = [
                    'shop_id'       => $homeShopId,
                    'category'      => $categoryData['primary_category'],
                    'sub_category'  => $categoryData['sub_category']
                ];

                $nodeIds = $this->di->getObjectManager()->get(Category::class)->init($request_data)->getBrowseNodeIds($request_data);

                if (isset($nodeIds['success']) && $nodeIds['success']) {
                    if (array_key_exists($categoryData['browser_node_id'], $nodeIds['data'])) {
                        $categorisedProducts[$key]['category']['browser_node_label'] = $nodeIds['data'][$categoryData['browser_node_id']];
                    }
                }
            }
        }
    }

    public function getProductsForBulkEdit($rawBody)
    {

        try {
            if (isset($rawBody['source_product_ids'])) {
                if (is_array($rawBody['source_product_ids'])) {
                    $ids = [];
                    foreach ($rawBody['source_product_ids'] as $id) {
                        $ids[] = (string)$id;
                    }

                    $filterData = [
                        'user_id'           => $this->di->getUser()->id,
                        'source_product_id' => ['$in' => $ids],
                        // 'group_id'          => ['$exists' => false]
                    ];

                    $selectFields = [
                        'projection' => ['title' =>  1, 'main_image' => 1, 'source_product_id' => 1, 'variant_title' => 1],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
                    $res = $collection->find($filterData, $selectFields);

                    $rows = [];
                    /*foreach ($res->toArray() as $value) {
                        $rows[] = $value->getArrayCopy();
                    }*/

                    $container_ids = [];
                    foreach ($res as $value) {
                        $sourceProductId = $value['source_product_id'];
                        if (array_key_exists('variant_title', $value)) {
                            $rows[$sourceProductId] = $value;
                        } else {
                            if (!in_array($sourceProductId, $container_ids)) {
                                $container_ids[] = $sourceProductId;
                            }
                        }
                    }

                    if (!empty($container_ids)) {
                        $filterData = [
                            'user_id'           => $this->di->getUser()->id,
                            'group_id'          => ['$in' => $container_ids],
                        ];

                        $selectFields = [
                            'projection' => ['title' =>  1, 'main_image' => 1, 'source_product_id' => 1, 'variant_title' => 1],
                            'typeMap'   => ['root' => 'array', 'document' => 'array']
                        ];

                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
                        $res = $collection->find($filterData, $selectFields);

                        foreach ($res as $value) {
                            if (array_key_exists('variant_title', $value)) {
                                $rows[$value['source_product_id']] = $value;
                            }
                        }
                    }

                    $responseData = [
                        'success' => true,
                        'data' => [
                            'current_count' => count($rows),
                            'rows' => array_values($rows)
                        ]
                    ];

                    return $responseData;
                }
                return ['success' => false, 'message' => 'source_product_ids must be array'];
            }
            if (isset($rawBody['container_ids'])) {
                if (is_array($rawBody['container_ids'])) {
                    $formatted_products = [];

                    $containerIds = [];
                    foreach ($rawBody['container_ids'] as $id) {
                        $containerIds[] = (string)$id;
                    }

                    $filterData = [
                        'user_id'           => $this->di->getUser()->id,
                        'container_id'      => ['$in' => $containerIds],
                    ];

                    $selectFields = [
                        'projection' => ['container_id' => 1, 'source_product_id' => 1, 'group_id' => 1, 'title' =>  1, 'main_image' => 1, 'variant_title' => 1, 'variant_attributes' => 1, 'sku' => 1],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

                    $amazonProductContainer = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
                    $products = $collection->find($filterData, $selectFields);

                    foreach ($products as $product) {
                        $amazonProduct = $amazonProductContainer->findOne(['source_product_id' => $product['source_product_id']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                        if (isset($amazonProduct['parent_sku'])) {
                            $formatted_products[$product['container_id']]['parent_sku'] = $amazonProduct['parent_sku'];
                        }

                        if (array_key_exists('group_id', $product)) {
                            $formatted_products[$product['container_id']]['variants'][] = [
                                'source_product_id' => $product['source_product_id'],
                                'variant_title'     => $product['variant_title'],
                                'main_image'        => $product['main_image'],
                                'handling_time'     => $amazonProduct['inventory_fulfillment_latency']
                            ];
                            $formatted_products[$product['container_id']]['handling_time'] = $amazonProduct['inventory_fulfillment_latency'];
                        } else {
                            if (array_key_exists('variant_title', $product)) {
                                $formatted_products[$product['container_id']] = [
                                    'container_id'  => $product['container_id'],
                                    'title'         => $product['title'],
                                    'handling_time' => $amazonProduct['inventory_fulfillment_latency'],
                                    'main_image'    => $product['main_image'],
                                    'variant_attributes' => $product['variant_attributes'] ?? [],
                                    'variants'      => [
                                        [
                                            'source_product_id' => $product['source_product_id'],
                                            'variant_title'     => $product['variant_title'],
                                            'main_image'        => $product['main_image']
                                        ]
                                    ]
                                ];
                            } else {



                                $formatted_products[$product['container_id']]['container_id']       = $product['container_id'];
                                $formatted_products[$product['container_id']]['title']              = $product['title'];
                                $formatted_products[$product['container_id']]['main_image']         = $product['main_image'];
                                $formatted_products[$product['container_id']]['variant_attributes'] = $product['variant_attributes'] ?? [];
                            }
                        }
                    }

                    $this->setEditedDataInProductsForBulkEdit($formatted_products);

                    $responseData = [
                        'success' => true,
                        'data' => [
                            'current_count' => count($formatted_products),
                            'rows' => array_values($formatted_products)
                        ]
                    ];

                    return $responseData;
                }
                return ['success' => false, 'message' => 'container_ids must be array'];
            } else {
                return ['success' => false, 'message' => 'either source_product_ids or container_ids are required.'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function setEditedDataInProductsForBulkEdit(&$products): void
    {
        if (!empty($products)) {
            $containerIds = array_keys($products);

            $request_data = [
                'target_marketplace'    => 'amazon',
                'container_ids'         => $containerIds
            ];

            $resp = $this->getEditedProduct($request_data);

            if ($resp['success']) {
                $editedProducts = $resp['data'];

                // $formattedEditedProducts = [];
                // foreach ($editedProducts as $cId => $editedProduct) {
                //     $formattedEditedProducts[$cId] = $this->formatEditedProduct($editedProduct);
                // }
                $formattedEditedProducts = $editedProducts;

                foreach ($products as $container_id => $product) {
                    if (array_key_exists($container_id, $formattedEditedProducts)) {
                        $products[$container_id]['edited_data'] = $formattedEditedProducts[$container_id];
                    }
                }
            }
        }
    }

    public function saveBulkProductEdit($rawBody)
    {
        try {
            $response = [];

            if (isset($rawBody['product_data'])) {
                $productData = $rawBody['product_data'];

                $amazonSellerIds = [];
                if (!empty($productData['amazon_seller_ids_array']) && is_array($productData['amazon_seller_ids_array'])) {
                    foreach ($productData['amazon_seller_ids_array'] as $amazonSellerData) {
                        $amazonSellerIds[] = $amazonSellerData['id'];
                    }
                } elseif (!empty($productData['amazon_seller_id']) && is_numeric($productData['amazon_seller_id'])) {
                    $amazonSellerIds[] = $productData['amazon_seller_id'];
                }

                if (!empty($amazonSellerIds) && isset($productData['target_marketplace'])) {
                    $targetMarketplace = $productData['target_marketplace'];

                    if (isset($productData['category_settings']) && is_array($productData['category_settings'])) {
                        $categorySettings = $productData['category_settings'];

                        if (isset($categorySettings['primary_category'], $categorySettings['sub_category'], $categorySettings['browser_node_id'], $categorySettings['barcode_exemption'])) {
                            $selected_category = [
                                "primary_category"  => $categorySettings['primary_category'],
                                "sub_category"      => $categorySettings['sub_category'],
                                "browser_node_id"   => $categorySettings['browser_node_id'],
                                "barcode_exemption" => $categorySettings['barcode_exemption'],
                            ];

                            if (isset($categorySettings['attributes_mapping']['required_attribute'])) {

                                $failed_response = [];
                                $response = ['success' => true, 'message' => 'Product saved successfully.'];

                                $products = $categorySettings['attributes_mapping']['required_attribute'];
                                foreach ($products as $product) {
                                    $containerId = $product['container_id'];

                                    $categoryNattributeData = $selected_category;

                                    $categoryNattributeData['attributes_mapping'] = [
                                        'required_attribute' => $this->validateAttributeMapping($product['required_attribute_mapping'])
                                    ];

                                    unset($product['required_attribute_mapping']);

                                    $product['category_settings'] = $categoryNattributeData;

                                    $resp = $this->saveBulkProduct($targetMarketplace, $containerId, $product, $amazonSellerIds);
                                    if ($resp['success'] === false) {
                                        $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                                        $failed_response[] = $resp;
                                    }
                                }

                                if (!empty($failed_response)) {
                                    $response['failed'] = $failed_response;
                                }
                            } else {
                                $response = ['success' => false, 'message' => 'invalid format.'];
                            }
                        } else {
                            $response = ['success' => false, 'message' => 'primary_category, sub_category, browser_node_id & barcode_exemption are required inside category_settings array.'];
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'category_settings data is required and must be array.'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'amazon_seller_id / amazon_seller_ids_array & target_marketplace are required.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'product_data is required.'];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function validateAttributeMapping($mapping)
    {
        return $mapping;
    }

    private function saveBulkProduct($targetMarketplace, $containerId, $product, $amazonSellerIds): array
    {
        $response = [];

        if (isset($product['variant_attributes'], $product['variants'])) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

            $failed_response = [];
            $response = ['success' => true, 'message' => 'Product saved successfully.'];

            /** SAVE CONTAINER **/
            $resp = $this->saveBulkProductContainer($targetMarketplace, $containerId, $product, $amazonSellerIds);
            if ($resp['success'] === false) {
                $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                $failed_response[] = $resp;
            }

            /** SAVE CONTAINER END **/

            /** SAVE VARIATIONS **/
            $originalDataArray = [];
            $items = $marketplaceProductCollection->find(['container_id' => (string)$containerId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if ($items) {
                foreach ($items as $item) {
                    if (isset($item['group_id'])) {
                        $originalDataArray[$item['source_product_id']] = $item;
                    }
                }
            }

            $availableSourceProductIds = [];

            foreach ($product['variants'] as $variant) {
                if (isset($variant['source_product_id'])) {
                    $availableSourceProductIds[] = $variant['source_product_id'];

                    $originalData = $originalDataArray[$variant['source_product_id']] ?? [];
                    $editedData = [];
                    $unset = [];

                    foreach ($amazonSellerIds as $amazonSellerId) {
                        $editedData['shops'][$amazonSellerId] = ['category_settings' => $product['category_settings']];
                    }

                    if (count($product['variant_attributes']) > 0) {
                        if (!isset($originalData['group_id'])) {
                            $editedData['group_id'] = $containerId;
                        }
                    } elseif (isset($originalData['group_id'])) {
                        $unset['group_id'] = 1;
                    }

                    if (!empty($unset)) {
                        $marketplaceProductCollection->updateOne(['source_product_id' => (string)$variant['source_product_id']], ['$unset' => $unset]);
                    }

                    if (!empty($editedData)) {
                        $editedData['user_id'] = $this->di->getUser()->id;
                        $editedData['container_id'] = $containerId;

                        $productEditData = [
                            'target_marketplace' => $targetMarketplace,
                            'source_product_id'  => $variant['source_product_id'],
                            'edited'             => $editedData
                        ];

                        $productModel = new ProductContainer();
                        $resp = $productModel->editedProduct($productEditData);

                        if ($resp['success'] === false) {
                            $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                            $failed_response[] = $resp;
                        }
                    }
                }
            }

            $deleteSourceProIds = [];
            foreach ($originalDataArray as $sourceProductId => $value) {
                if (!in_array($sourceProductId, $availableSourceProductIds)) {
                    $deleteSourceProIds[] = (string)$sourceProductId;
                }
            }

            if (!empty($deleteSourceProIds)) {
                $marketplaceProductCollection->deleteMany([
                    'user_id' => $this->di->getUser()->id,
                    'source_product_id' => ['$in' => $deleteSourceProIds]
                ], ['w' => true]);
            }

            /** SAVE VARIATIONS **/

            if (!empty($failed_response)) {
                $response['failed'] = $failed_response;
            }
        } else {
            $response = ['success' => false, 'message' => 'variant_attributes & variants is required.'];
        }

        return $response;
    }

    private function saveBulkProductContainer($targetMarketplace, $containerId, $product, $amazonSellerIds)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

        $originalContainerData = $marketplaceProductCollection->findOne(['source_product_id' => (string)$containerId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        // $originalContainerData = $marketplaceProductCollection->findOne(['container_id'=>(string)$containerId,'group_id'=>['$exists'=>0]], ['typeMap'=>['root'=>'array', 'document'=>'array']]);

        if (!$originalContainerData) {
            $originalContainerData = [];
        }

        // if(count($product['variants']) > 1)
        if (count($product['variant_attributes']) > 0) {
            $containerData = [];

            $categoryMapping = $product['category_settings'];
            unset($categoryMapping['attributes_mapping']);

            foreach ($amazonSellerIds as $amazonSellerId) {
                $containerData['shops'][$amazonSellerId] = ['category_settings' => $categoryMapping];
            }

            if (!empty($containerData)) {
                $containerData['user_id'] = $this->di->getUser()->id;
                $containerData['container_id'] = $containerId;

                $productEditData = [
                    'target_marketplace' => $targetMarketplace,
                    'source_product_id'  => $containerId,
                    'edited'             => $containerData
                ];

                $productModel = new ProductContainer();
                return $productModel->editedProduct($productEditData);
            }
        } else {
            if (!empty($originalContainerData)) {
                $response = $marketplaceProductCollection->deleteOne(['source_product_id' => (string)$containerId], ['w' => true]);

                if ($response->isAcknowledged()) {
                    return ['success' => true];
                }
                return ['success' => false];
            }
        }

        return ['success' => true];
    }

    public function getProductsCount($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getPhpCollection();

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        // $aggregation[] = [
        //     '$graphLookup' => [
        //         "from"              => "product_container",
        //         "startWith"         => '$container_id',
        //         "connectFromField"  => "container_id",
        //         "connectToField"    => "group_id",
        //         "as"                => "variants",
        //         "maxDepth"          => 1
        //     ]
        // ];

        // $aggregation[] = [
        //     '$match' => [
        //         '$or' => [
        //             ['$and' => [
        //                 ['type' => 'variation'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //             ['$and' => [
        //                 ['type' => 'simple'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //         ],
        //     ],
        // ];
        if (isset($params['filter']['variant_attributes'])) {
            $temp = [];

            if (isset($params['filter']['variant_attributes'][3])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][3]);
            } else if (isset($params['filter']['variant_attributes'][1])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][1]);
            }

            unset($params['filter']['variant_attributes']);
            // $params['filter']['variant_attributes']=$temp;
            $aggregation[] = ['$match' => ['variant_attributes' =>  ['$eq' => $temp]]];
        }

        if (isset($params['filter']['type'])) {

            if ($params['filter']['type'][1] == "simple") {

                $aggregation[] = ['$match' => ['type' => 'simple', 'group_id' => ['$exists' => false]]];
            } else if ($params['filter']['type'][1] == "variation") {

                $aggregation[] = ['$match' => ['type' => 'variation', 'visibility' => 'Catalog and Search']];
            }

            unset($params['filter']['type']);
        }

        if (isset($params['filter']['activity'])) {

            if ($params['filter']['activity'][3] == "Error") {
                $aggregation[] = ['$match' => ['marketplace.amazon.0.error' =>  ['$exists' => true, '$ne' => (object) []]]];
            } else {

                // $aggregation[] = ['$match' => ['marketplace.amazon.0.error' => ['$not' => ['$exists' => true]]]];
                $aggregation[] = ['$match' => ['marketplace.amazon.0.process_tags' =>  ['$exists' => true, '$ne' => []]]];
            }

            unset($params['filter']['activity']);
        }

        if (isset($params['filter']['sku'])) {

            $amazon_product_container = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

            if (isset($params['filter']['sku'][4]) || isset($params['filter']['sku'][2])) {
                $amazon_data = $amazon_product_container->find(['parent_sku' => ['$ne' => (string)$params['filter']['sku'][array_keys($params['filter']['sku'])[0]]]]);
            } else {

                $amazon_data = $amazon_product_container->find(['parent_sku' => (string)$params['filter']['sku'][array_keys($params['filter']['sku'])[0]]]);
            }

            $amazon_data = $amazon_data->toArray();

            if (count($amazon_data) > 0) {
                if (count(array_column($amazon_data, 'source_product_id')) > 1) {
                    $source = array_column($amazon_data, 'source_product_id');
                    $source = implode(",", $source);
                    $source = explode(",", $source);

                    $para['filter']['source_product_id'][3] = $source;
                } else {

                    $para['filter']['source_product_id'][3] = (string)$amazon_data[0]['source_product_id'];
                }
            } else {

                $para['filter']['source_product_id'] = $params['filter']['sku'];
            }

            $aggregate = array_merge($aggregation, $this->buildAggregateQuery($para));

            $data = $collection->aggregate($aggregate);


            if (iterator_count($data) > 0) {

                $params['filter']['source_product_id'] = $para['filter']['source_product_id'];
                unset($params['filter']['sku']);
            }
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params, 'productCount'));

        $aggregation[] = [
            '$group' => [
                '_id' => '$container_id'
            ]
        ];

        $aggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($aggregation)->toArray();
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()/*,'data' => $aggregation*/];
        }

        $responseData = [
            'success' => true,
            'query' => $aggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }

    public function getProducts($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();
        $amazon_product_container = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
        $totalPageRead = 1;
        $limit = $params['count'] ?? ProductContainer::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode((string) $params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        if (isset($params['filter']['variant_attributes'])) {
            $temp = [];
            if (isset($params['filter']['variant_attributes'][3])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][3]);
            } else if (isset($params['filter']['variant_attributes'][1])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][1]);
            }

            unset($params['filter']['variant_attributes']);

            $aggregation[] = ['$match' => ['variant_attributes' =>  ['$eq' => $temp]]];
        }

        if (isset($params['filter']['type'])) {

            if ($params['filter']['type'][1] == "simple") {

                $aggregation[] = ['$match' => ['type' => 'simple', 'group_id' => ['$exists' => false]]];
            } else if ($params['filter']['type'][1] == "variation") {

                $aggregation[] = ['$match' => ['type' => 'variation', 'visibility' => 'Catalog and Search']];
            }

            unset($params['filter']['type']);
        }

        if (isset($params['filter']['activity'])) {

            if ($params['filter']['activity'][3] == "Error") {
                $aggregation[] = ['$match' => ['marketplace.amazon.0.error' =>  ['$exists' => true, '$ne' => (object) []]]];
            } else {

                // $aggregation[] = ['$match' => ['marketplace.amazon.0.error' => ['$not' => ['$exists' => true]]]];
                $aggregation[] = ['$match' => ['marketplace.amazon.0.process_tags' =>  ['$exists' => true, '$ne' => []]]];
            }

            unset($params['filter']['activity']);
        }

        if (isset($params['filter']['sku'])) {



            if (isset($params['filter']['sku'][4]) || isset($params['filter']['sku'][2])) {
                $amazon_data = $amazon_product_container->find(['parent_sku' => ['$ne' => (string)$params['filter']['sku'][array_keys($params['filter']['sku'])[0]]]]);
            } else {

                $amazon_data = $amazon_product_container->find(['parent_sku' => (string)$params['filter']['sku'][array_keys($params['filter']['sku'])[0]]]);
            }

            $amazon_data = $amazon_data->toArray();

            if (count($amazon_data) > 0) {
                if (count(array_column($amazon_data, 'source_product_id')) > 1) {
                    $source = array_column($amazon_data, 'source_product_id');
                    $source = implode(",", $source);
                    $source = explode(",", $source);

                    $para['filter']['source_product_id'][3] = $source;
                } else {

                    $para['filter']['source_product_id'][3] = (string)$amazon_data[0]['source_product_id'];
                }
            } else {

                $para['filter']['source_product_id'] = $params['filter']['sku'];
            }

            $aggregate = array_merge($aggregation, $this->buildAggregateQuery($para));

            $data = $collection->aggregate($aggregate);


            if (iterator_count($data) > 0) {

                $params['filter']['source_product_id'] = $para['filter']['source_product_id'];
                unset($params['filter']['sku']);
            }
        }

        $aggregation[] = [
            '$sort' => ['visibility' => 1]
        ];

        $aggregation[] = [
            '$sort' => ['container_id' => 1]
        ];
        // $aggregation[] = [
        //     '$match' => ['type' => 'variation']
        // ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        if (in_array($this->di->getUser()->id, ['6116eb5b91b6367b2e5c41f2', '613edca8807b015973566456', '611cbdc45a71ee43e7008871', '6130e5adf6725006e115e6aa', '6141b56537089758e46a1a3b', '61d5d8acc5695878cd538c87', '610802aad6b67135c06a4876', '6170537fa4ece7266e124fdd', '61d897b05ace9d63507ca2d7', '61dc9bb3e4931d14d34ac02d', '61d4672b64303b24b43ac7ca', '61d5ab1f79cbb17796473307', '6186c61d65b4602ea84117f9', '6121d32b3e16e7665759633f'])) {
            $_group = ['_id' => '$container_id'];
            $aggregation[] = [
                '$group' => $_group
            ];

            $aggregation[] = [
                '$sort' => ['_id' => 1]
            ];

            if (isset($offset)) {
                $aggregation[] = ['$skip' => (int) $offset];
            }

            $aggregation[] = ['$limit' => (int) $limit + 1];

            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

            $cursor = $collection->aggregate($aggregation, $options);
            $it = new IteratorIterator($cursor);
            $it->rewind();
            // Very important
            $rows = [];
            $distinctContainerIds = [];

            while ($limit > 0 && $doc = $it->current()) {
                if (!in_array((string)$doc['_id'], $distinctContainerIds)) {
                    $distinctContainerIds[] = (string)$doc['_id'];

                    $limit--;
                }

                $it->next();
            }

            $productStatuses = $this->prepareProductsStatus($distinctContainerIds);

            $rows = array_values($productStatuses);

            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }

            $responseData = [
                'success' => true,
                // 'query' =>  $aggregation,
                'data' => [
                    'totalPageRead' => $page ?? $totalPageRead,
                    'current_count' => count($rows),
                    'rows' => $rows,
                    'next' => $next ?? null
                ]
            ];

            return $responseData;
        }
        // $select_fields = ['sku', 'user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'marketplace', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'quantity', 'inventory_management', 'inventory_policy'];
        $select_fields = ['sku', 'user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'quantity', 'inventory_management', 'inventory_policy'];
        foreach ($select_fields as $field) {
            $pro[$field] = true;
        }
        $_group = [
            '_id' => '$container_id',
            'root' => [
                '$push' => '$$ROOT'
            ]
        ];
        $aggregation[] = [
            '$project' => $pro
        ];
        $aggregation[] = [
            '$group' => $_group
        ];
        $aggregation[] = [
            '$sort' => ['_id' => 1]
        ];
        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }
        $aggregation[] = ['$limit' => (int) $limit + 1];
        try {
            // use this below code get to slow down
            //TODO: TEST Needed here
            // $rows = $collection->aggregate($aggregation)->toArray();
            // print_r($aggregation);die;
            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true];
            $cursor = $collection->aggregate($aggregation, $options);
            $it = new IteratorIterator($cursor);
            $it->rewind();
            // Very important
            $rows = [];
            $distinctContainerIds = [];

            while ($limit > 0 && $doc = $it->current()) {
                if (!in_array((string)$doc['container_id'], $distinctContainerIds)) {
                    $doc = $doc['root'][0];
                    $distinctContainerIds[] = (string)$doc['container_id'];
                    $rows[$doc['container_id']] = [
                        'user_id'           => $doc['user_id'],
                        'container_id'      => $doc['container_id'],
                        'main_image'        => $doc['main_image'],
                        'title'             => $doc['title'],
                        'additional_images' => $doc['additional_images'],
                        // 'type'              => $doc['type'],
                        'brand'             => $doc['brand'],
                        'description'       => $doc['description'] ?? '',
                        'product_type'      => $doc['product_type'],
                        // 'visibility'        => $doc['visibility'],
                        'app_codes'         => $doc['app_codes'],
                        'variant_attributes' => $doc['variant_attributes'],
                        'inventory_policy' => $doc['inventory_policy'],
                    ];
                    // $rows[$doc['container_id']]=$doc;

                    if (!empty($doc['profile'])) {
                        $rows[$doc['container_id']]['profile'] = $doc['profile'];
                    }

                    if (array_key_exists('inventory_management', $doc)) {
                        $rows[$doc['container_id']]['inventory_management'] = $doc['inventory_management'];
                    }

                    if (isset($doc['quantity'])) {
                        $rows[$doc['container_id']]['quantity'] = $doc['quantity'];
                    }

                    if (!empty($doc['marketplace'])) {
                        $rows[$doc['container_id']]['marketplace'] = $doc['marketplace'];
                    }

                    if (is_null($doc['sku']) || $doc['sku'] == "") {

                        $rows[$doc['container_id']]['sku'] = $doc['source_product_id'];
                    } else {
                        $rows[$doc['container_id']]['sku'] = $doc['sku'];
                    }

                    $limit--;
                }

                $it->next();
            }

            $settings = $this->getProductSettings($userId, $rows, ['inventory']);
            $productStatuses = $this->prepareProductsStatus($distinctContainerIds, $settings);

            /* Code to append Edited Data starts*/
            $getEditedData = $this->getEditedData($distinctContainerIds);
            foreach ($rows as $c_id => $row) {
                $newCurrentVariants = [];
                if (isset($getEditedData[$c_id]) && !empty($getEditedData[$c_id])) {
                    $rows[$c_id]['edited'] = $getEditedData[$c_id];
                    $currentVariants = $productStatuses[$c_id]['variations'];
                    if (!empty($currentVariants)) {
                        foreach ($currentVariants as $variant) {
                            if (isset($getEditedData[$c_id][$variant['source_product_id']]['barcode'])) {
                                $variant['barcode'] = $getEditedData[$c_id][$variant['source_product_id']]['barcode'];
                            }

                            if (isset($getEditedData[$c_id][$variant['source_product_id']]['sku'])) {
                                $variant['sku'] = $getEditedData[$c_id][$variant['source_product_id']]['sku'];
                            }

                            $newCurrentVariants[] = $variant;
                        }
                    }
                } else {
                    $newCurrentVariants = $productStatuses[$c_id]['variations'];
                }

                $rows[$c_id]['variants'] = $newCurrentVariants ?? [];
                $rows[$c_id]['source_product_id'] = $productStatuses[$c_id]['container_source_product_id'];
                $rows[$c_id]['type'] = $productStatuses[$c_id]['container_type'];
                $rows[$c_id]['visibility'] = $productStatuses[$c_id]['container_visibility'];

                $amazonDatas = $getEditedData[$c_id][$productStatuses[$c_id]['container_source_product_id']];

                if (isset($amazonDatas['parent_sku'])) {
                    $rows[$c_id]['parent_sku'] = $amazonDatas['parent_sku'];
                } else if (isset($amazonDatas['sku'])) {
                    $rows[$c_id]['parent_sku'] = $amazonDatas['sku'];
                }

                //to add quantity in simple product - part 2
                if (isset($productStatuses[$c_id]['quantity'])) {
                    $rows[$c_id]['quantity'] = $productStatuses[$c_id]['quantity'];
                }

                if (array_key_exists('quantity', $row)) {
                    if (isset($settings[$c_id]['inventory'])) {
                        $inv_setting = $settings[$c_id]['inventory'];

                        if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                            $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                            $calculatedInv = $productInventory->init()->calculateNew($row, $inv_setting);
                            if (isset($calculatedInv['Quantity'])) {
                                $rows[$c_id]['quantity'] = $calculatedInv['Quantity'];
                            }
                        }
                    }
                }
            }

            $rows = array_values($rows);

            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // 'data' => $aggregation
            ];
        }
        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null
            ]
        ];
        return $responseData;
    }

    /*public function getProducts($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getPhpCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? ProductContainer::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        $aggregation[] = [
            '$graphLookup' => [
                "from"              => "product_container",
                "startWith"         => '$container_id',
                "connectFromField"  => "container_id",
                "connectToField"    => "group_id",
                "as"                => "variants",
                "maxDepth"          => 1
            ]
        ];

        $aggregation[] = [
            '$match' => [
                '$or' => [
                    ['$and' => [
                        ['type' => 'variation'],
                        ["visibility" => 'Catalog and Search'],
                    ]],
                    ['$and' => [
                        ['type' => 'simple'],
                        ["visibility" => 'Catalog and Search'],
                    ]],
                ],
            ],
        ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];

        try {
            // use this below code get to slow down
            //TODO: TEST Needed here
            // $rows = $collection->aggregate($aggregation)->toArray();
            // print_r($aggregation);
            // die;
            $cursor = $collection->aggregate($aggregation);
            $it = new \IteratorIterator($cursor);
            $it->rewind(); // Very important

            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                $limit--;
                $it->next();
            }
            // var_dump($it->isDead());die;
            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // 'data' => $aggregation
            ];
        }

        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null
            ]
        ];

        return $responseData;
    }*/

    private function buildAggregateQuery($params, $callType = 'getProduct'): array
    {
        $productOnly = false;
        $aggregation = [];
        $andQuery = [];
        $orQuery = [];
        $filterType = '$and';

        if (isset($params['productOnly'])) {
            $productOnly = $params['productOnly'] === "true";
        }

        if (isset($params['filterType'])) {
            $filterType = '$' . $params['filterType'];
        }

        if (isset($params['filter']) || isset($params['search'])) {
            // $andQuery = self::search($params);
            $andQuery = ProductContainer::search($params);
        }

        if (isset($params['or_filter'])) {
            // $orQuery = self::search(['filter' => $params['or_filter']]);
            $orQuery = ProductContainer::search(['filter' => $params['or_filter']]);
            $temp = [];
            foreach ($orQuery as $optionKey => $orQueryValue) {
                $temp[] = [
                    $optionKey => $orQueryValue,
                ];
            }

            $orQuery = $temp;
        }

        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode((string) $params['next']), true);
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$lt' => $params['prev'],
                ]],
            ];
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => ['container_id' => [
                    '$in' => $params['container_ids']
                ]],
            ];
        }

        if (count($andQuery)) {
            $aggregation[] = ['$match' => [
                $filterType => [
                    $andQuery,
                ],
            ]];
        }

        if ($orQuery !== []) {
            $aggregation[] = ['$match' => [
                '$or' => $orQuery,
            ]];
        }

        return $aggregation;
    }

    /*private static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);

                if($key === 'template_name')
                {
                    $conditions["profile.profile_name"] = $value[ProductContainer::IS_EQUAL_TO];
                }
                elseif($key === 'status')
                {
                    $marketplace = $filterParams['marketplace'] ?? '';
                    if($marketplace) {
                        if($value[ProductContainer::IS_EQUAL_TO] === 'Disabled' || $value[ProductContainer::IS_EQUAL_TO] === 'Not_listed') {
                            $disabledFilter = [];

                            // Search in product
                            $disabledFilter[] = ["marketplace.{$marketplace}" => ['$exists'=>0]];

                            $disabledFilter[] = ["marketplace.{$marketplace}.status" => ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Supressed', 'Available for Offer']]];

                            // Search in variants
                            $disabledFilter[] = ["variants.marketplace.{$marketplace}" => ['$exists'=>0]];

                            $disabledFilter[] = ["variants.marketplace.{$marketplace}.status" => ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Supressed', 'Available for Offer']]];

                            $conditions['$or'] = $disabledFilter;
                        }
                        else {
                            $statusFilter = [];

                            // Search in product
                            $statusFilter[] = ["marketplace.{$marketplace}.status" => $value[ProductContainer::IS_EQUAL_TO]];

                            // Search in variants
                            $statusFilter[] = ["variants.marketplace.{$marketplace}.status" => $value[ProductContainer::IS_EQUAL_TO]];

                            $conditions['$or'] = $statusFilter;
                        }
                    }
                }
                else
                {
                    $variant_filters = ['quantity', 'sku', 'price', 'tags'];

                    if (array_key_exists(ProductContainer::IS_EQUAL_TO, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => ProductContainer::checkInteger($key, $value[ProductContainer::IS_EQUAL_TO])];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => ProductContainer::checkInteger($key, $value[ProductContainer::IS_EQUAL_TO])];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = ProductContainer::checkInteger($key, $value[ProductContainer::IS_EQUAL_TO]);
                        }
                    }
                    elseif (array_key_exists(ProductContainer::IS_NOT_EQUAL_TO, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => ['$ne' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_NOT_EQUAL_TO]))]];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => ['$ne' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_NOT_EQUAL_TO]))]];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = ['$ne' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_NOT_EQUAL_TO]))];
                        }
                    }
                    elseif (array_key_exists(ProductContainer::IS_CONTAINS, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_CONTAINS]))),
                                '$options' => 'i'
                            ]];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_CONTAINS]))),
                                '$options' => 'i'
                            ]];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_CONTAINS]))),
                                '$options' => 'i'
                            ];
                        }
                    }
                    elseif (array_key_exists(ProductContainer::IS_NOT_CONTAINS, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => [
                                '$regex' => "^((?!" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_NOT_CONTAINS]))) . ").)*$",
                                '$options' => 'i'
                            ]];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => [
                                '$regex' => "^((?!" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_NOT_CONTAINS]))) . ").)*$",
                                '$options' => 'i'
                            ]];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = [
                                '$regex' => "^((?!" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::IS_NOT_CONTAINS]))) . ").)*$",
                                '$options' => 'i'
                            ];
                        }
                    }
                    elseif (array_key_exists(ProductContainer::START_FROM, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => [
                                '$regex' => "^" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::START_FROM]))),
                                '$options' => 'i'
                            ]];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => [
                                '$regex' => "^" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::START_FROM]))),
                                '$options' => 'i'
                            ]];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = [
                                '$regex' => "^" . ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::START_FROM]))),
                                '$options' => 'i'
                            ];
                        }
                    }
                    elseif (array_key_exists(ProductContainer::END_FROM, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];
                            // Search in product
                            $filter[] = ["{$key}" => [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::END_FROM]))) . "$",
                                '$options' => 'i'
                            ]];

                            // Search in variants
                            $filter[] = ["variants.{$key}" => [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::END_FROM]))) . "$",
                                '$options' => 'i'
                            ]];

                            $conditions['$or'] = $filter;
                        }
                        else {
                            $conditions[$key] = [
                                '$regex' => ProductContainer::checkInteger($key, trim(addslashes($value[ProductContainer::END_FROM]))) . "$",
                                '$options' => 'i'
                            ];
                        }
                    }
                    elseif (array_key_exists(ProductContainer::RANGE, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];

                            if (trim($value[ProductContainer::RANGE]['from']) && !trim($value[ProductContainer::RANGE]['to'])) {
                                // Search in product
                                $filter[] = ["{$key}" => ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from']))]];

                                // Search in variants
                                $filter[] = ["variants.{$key}" => ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from']))]];
                            }
                            elseif (trim($value[ProductContainer::RANGE]['to']) && !trim($value[ProductContainer::RANGE]['from'])) {
                                // Search in product
                                $filter[] = ["{$key}" => ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to']))]];

                                // Search in variants
                                $filter[] = ["variants.{$key}" => ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to']))]];
                            }
                            else {
                                // Search in product
                                $filter[] = ["{$key}" => [
                                    '$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from'])),
                                    '$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to'])),
                                ]];

                                // Search in variants
                                $filter[] = ["variants.{$key}" => [
                                    '$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from'])),
                                    '$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to'])),
                                ]];
                            }

                            $conditions['$or'] = $filter;
                        }
                        else {
                            if (trim($value[ProductContainer::RANGE]['from']) && !trim($value[ProductContainer::RANGE]['to'])) {
                                $conditions[$key] = ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from']))];
                            } elseif (trim($value[ProductContainer::RANGE]['to']) &&
                                !trim($value[ProductContainer::RANGE]['from'])) {
                                $conditions[$key] = ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to']))];
                            } else {
                                $conditions[$key] = [
                                    '$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['from'])),
                                    '$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::RANGE]['to'])),
                                ];
                            }
                        }
                    }
                    elseif (array_key_exists(ProductContainer::IS_GREATER_THAN, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];

                            if (is_numeric(trim($value[ProductContainer::IS_GREATER_THAN]))) {
                                // Search in product
                                $filter[] = ["{$key}" => ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_GREATER_THAN]))]];

                                // Search in variants
                                $filter[] = ["variants.{$key}" => ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_GREATER_THAN]))]];

                                $conditions['$or'] = $filter;
                            }
                        }
                        else {
                            if (is_numeric(trim($value[ProductContainer::IS_GREATER_THAN]))) {
                                $conditions[$key] = ['$gte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_GREATER_THAN]))];
                            }
                        }
                    }
                    elseif (array_key_exists(ProductContainer::IS_LESS_THAN, $value))
                    {
                        if(in_array($key, $variant_filters)) {
                            $filter = [];

                            if (is_numeric(trim($value[ProductContainer::IS_LESS_THAN]))) {
                                // Search in product
                                $filter[] = ["{$key}" => ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_LESS_THAN]))]];

                                // Search in variants
                                $filter[] = ["variants.{$key}" => ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_LESS_THAN]))]];

                                $conditions['$or'] = $filter;
                            }
                        }
                        else {
                            if (is_numeric(trim($value[ProductContainer::IS_LESS_THAN]))) {
                                $conditions[$key] = ['$lte' => ProductContainer::checkInteger($key, trim($value[ProductContainer::IS_LESS_THAN]))];
                            }
                        }
                    }
                }
            }
        }
        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => ProductContainer::checkInteger($key, trim(addslashes($filterParams['search'])))];
        }

        return $conditions;
    }*/

    private function prepareProductsStatus($container_ids, $settings = []): array
    {
        if (in_array($this->di->getUser()->id, ['6116eb5b91b6367b2e5c41f2', '613edca8807b015973566456', '611cbdc45a71ee43e7008871', '6130e5adf6725006e115e6aa', '6141b56537089758e46a1a3b', '61d5d8acc5695878cd538c87', '610802aad6b67135c06a4876', '6170537fa4ece7266e124fdd', '61d897b05ace9d63507ca2d7', '61dc9bb3e4931d14d34ac02d', '61d4672b64303b24b43ac7ca', '61d5ab1f79cbb17796473307', '6186c61d65b4602ea84117f9', '6121d32b3e16e7665759633f'])) {
            $status_arr = [];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();

            $aggregation = [];

            $aggregation[] = ['$sort' => ['visibility' => 1]];

            $aggregation[] = ['$match' => ['container_id' => ['$in' => $container_ids]]];

            $_group = [
                '_id'       => '$container_id',
                'variants'   => [
                    '$push' => [
                        'container_id'      => '$container_id',
                        'source_product_id' => '$source_product_id',
                        'sku'               => '$sku',
                        'variant_title'     => '$variant_title',
                        // 'marketplace'       => '$marketplace',
                        'type'              => '$type',
                        'visibility'        => '$visibility',
                        'locations'         => '$locations',
                        'quantity'          => '$quantity',
                        'barcode'           => '$barcode',
                        'variant_image'     => '$variant_image'
                    ]
                ]
            ];

            // $select_fields = ['user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'marketplace', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes'];
            $select_fields = ['user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes'];

            foreach ($select_fields as $field) {
                $_group[$field] = ['$first' => '$' . $field];
            }

            $aggregation[] = [
                '$group' => $_group
            ];

            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

            $products = $collection->aggregate($aggregation, $options)->toArray();

            foreach ($products as $product) {
                $status_arr[$product['_id']] = [
                    'user_id'           => $product['user_id'],
                    'container_id'      => $product['container_id'],
                    'main_image'        => $product['main_image'],
                    'title'             => $product['title'],
                    'additional_images' => $product['additional_images'],
                    // 'type'              => $product['type'],
                    'brand'             => $product['brand'],
                    'description'       => $product['description'] ?? '',
                    'product_type'      => $product['product_type'],
                    // 'visibility'        => $product['visibility'],
                    'app_codes'         => $product['app_codes'],
                    'variant_attributes' => $product['variant_attributes']
                ];

                if (!empty($product['profile'])) {
                    $status_arr[$product['_id']]['profile'] = $product['profile'];
                }

                if (!empty($product['marketplace'])) {
                    $status_arr[$product['_id']]['marketplace'] = $product['marketplace'];
                }

                if (count($product['variants']) == 1) {
                    $status_arr[$product['_id']]['source_product_id'] = current($product['variants'])['source_product_id'];
                    $status_arr[$product['_id']]['type'] = current($product['variants'])['type'];
                    $status_arr[$product['_id']]['visibility'] = current($product['variants'])['visibility'];

                    $status_arr[$product['_id']]['variants'] = [];
                } else {
                    foreach ($product['variants'] as $variant) {
                        if (!array_key_exists('variant_title', $variant)) {
                            $status_arr[$product['_id']]['source_product_id'] = $variant['source_product_id'];
                            $status_arr[$product['_id']]['type'] = $variant['type'];
                            $status_arr[$product['_id']]['visibility'] = $variant['visibility'];
                        } else {
                            $status_arr[$product['_id']]['variants'][] = $variant;
                        }
                    }
                }
            }

            return $status_arr;
        }
        $status_arr = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();
        $aggregation = [
            ['$sort' => ['visibility' => 1]],
            ['$match' => ['container_id' => ['$in' => $container_ids]]],
            [
                '$group' => [
                    '_id'       => '$container_id',
                    'variants'   => [
                        '$push' => [
                            'container_id'      => '$container_id',
                            'source_product_id' => '$source_product_id',
                            'sku'               => '$sku',
                            'variant_title'     => '$variant_title',
                            // 'marketplace'       => '$marketplace',
                            'type'              => '$type',
                            'visibility'        => '$visibility',
                            'locations'         => '$locations',
                            'quantity'          => '$quantity',
                            'barcode'           => '$barcode',
                            'variant_image'     => '$variant_image',
                            'inventory_management' => '$inventory_management',
                            'inventory_policy' => '$inventory_policy'
                        ]
                    ]
                ]
            ]
        ];
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true];
        $products = $collection->aggregate($aggregation, $options)->toArray();
        foreach ($products as $product) {
            if (count($product['variants']) == 1) {
                $simpleQuantity = current($product['variants'])['quantity'];
                $cId = $product['container_id'];
                if (array_key_exists('quantity', $product)) {
                    if (isset($settings[$cId]) && isset($settings[$cId]['inventory'])) {
                        $inv_setting = $settings[$cId]['inventory'];
                        // $this->di->getLog()->logContent('distinctContainerIds: ' . json_encode($inv_setting), 'info', 'distinctContainerIds.log');
                        if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                            $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                            $calculatedInv = $productInventory->init()->calculateNew($product, $inv_setting);
                            if (isset($calculatedInv['Quantity'])) {
                                $simpleQuantity = $calculatedInv['Quantity'];
                            }
                        }
                    }
                }

                $status_arr[$product['_id']]['container_source_product_id'] = current($product['variants'])['source_product_id'];
                $status_arr[$product['_id']]['container_type'] = current($product['variants'])['type'];
                $status_arr[$product['_id']]['container_visibility'] = current($product['variants'])['visibility'];
                // $simpleQuantity = current($product['variants'])['quantity']; //to add quantity in simple product - part 1
                $status_arr[$product['_id']]['quantity'] = ($simpleQuantity < 0) ? 0 : $simpleQuantity;
            } else {
                foreach ($product['variants'] as $variant) {
                    $cId = $variant['container_id'];
                    if (array_key_exists('quantity', $variant)) {
                        if (isset($settings[$cId]) && isset($settings[$cId]['inventory'])) {
                            $inv_setting = $settings[$cId]['inventory'];

                            if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                                $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                                $calculatedInv = $productInventory->init()->calculateNew($variant, $inv_setting);
                                if (isset($calculatedInv['Quantity'])) {
                                    $variant['quantity'] = $calculatedInv['Quantity'];
                                }
                            }
                        }
                    }

                    if (!array_key_exists('variant_title', $variant)) {
                        $status_arr[$product['_id']]['container_source_product_id'] = $variant['source_product_id'];
                        $status_arr[$product['_id']]['container_type'] = $variant['type'];
                        $status_arr[$product['_id']]['container_visibility'] = $variant['visibility'];
                    } else {
                        $status_arr[$product['_id']]['variations'][] = $variant;
                    }
                }
            }
        }

        return $status_arr;
    }

    public function getProduct($params)
    {
        if (isset($params['container_id'])) {
            $products = [];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
            $userId = $this->di->getUser()->id;

            try {
                $filter = ['container_id' => $params['container_id']];
                $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

                $products = $collection->find($filter, $options)->toArray();
                // print_r($products);
                // die("jelo");
                if ($products) {
                    $amazonCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
                    $amazonProducts = $amazonCollection->find($filter, ['projection' => ['_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
                    // print_r($amazonProducts);
                    // die("lk");
                    $amazonProductsData = [];
                    foreach ($amazonProducts as $amazonProduct) {
                        $amazonProductsData[$amazonProduct['source_product_id']] = $amazonProduct;
                    }

                    // $settings = $this->getProductSettings($userId, $products);
                    $settings = $this->getProductSettings($userId, $products, ['category', 'inventory', 'pricing']);
                    // print_r($settings);
                    // die("kj");
                    foreach ($products as $key => $product) {
                        // print_r($product);die;
                        $cId = $product['container_id'];
                        if (array_key_exists('quantity', $product)) {
                            if (isset($settings[$cId]['inventory'])) {
                                $inv_setting = $settings[$cId]['inventory'];
                                if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                                    $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                                    $calculatedInv = $productInventory->init()->calculateNew($product, $inv_setting);
                                    if (isset($calculatedInv['Quantity'])) {
                                        $products[$key]['quantity'] = $calculatedInv['Quantity'];
                                    }
                                }
                            }
                        }

                        if (array_key_exists('price', $product)) {
                            if (isset($settings[$cId]['pricing'])) {
                                $productPrice = $this->di->getObjectManager()->get(Price::class);
                                $conversion_rate = $productPrice->init()->getConversionRate();
                                if ($conversion_rate === false) $conversion_rate = 1;

                                $price_setting = $settings[$cId]['pricing'];
                                if (isset($price_setting['settings_enabled']) && $price_setting['settings_enabled']) {
                                    $priceList = $productPrice->init()->calculateNew($product, $price_setting, $conversion_rate);
                                    if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                        $products[$key]['price'] = $priceList['StandardPrice'];
                                    }
                                } else {
                                    $priceList = $productPrice->init()->calculateNew($product, Helper::DEFAULT_PRICE_SETTING, $conversion_rate);
                                    if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                        $products[$key]['price'] = $priceList['StandardPrice'];
                                    }
                                }
                            } else {
                                $productPrice = $this->di->getObjectManager()->get(Price::class);
                                $conversion_rate = $productPrice->init()->getConversionRate();
                                if ($conversion_rate === false) $conversion_rate = 1;

                                $priceList = $productPrice->init()->calculateNew($product, Helper::DEFAULT_PRICE_SETTING, $conversion_rate);
                                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                    $products[$key]['price'] = $priceList['StandardPrice'];
                                }
                            }

                            if ($currencyCode = $this->getAmazonCurrency($userId)) {
                                $products[$key]['price_with_currency'] = "{$products[$key]['price']} {$currencyCode}";
                                $products[$key]['amazon_currency'] = $currencyCode;
                            }
                        }

                        $editedData = [];

                        if (isset($amazonProductsData[$product['source_product_id']])) {
                            $editedData = $amazonProductsData[$product['source_product_id']];
                        }

                        if (isset($settings[$product['container_id']])) {
                            $editedData['profile_data'] = $settings[$product['container_id']];

                            if (!isset($product['profile_id'])) {
                                $editedData['profile_id'] = $settings[$product['container_id']]['profile_id'] ?? 'default';
                                $editedData['profile_name'] = $settings[$product['container_id']]['profile_name'] ?? 'default';
                            }
                        }

                        if (!isset($editedData['shops']) && isset($settings[$cId]['category']['barcode_exemption']) && $settings[$cId]['category']['barcode_exemption'] === true) {
                            $userShops = $this->di->getUser()->shops;
                            foreach ($userShops as $userShop) {
                                if ($userShop['marketplace'] == 'amazon') {
                                    $products[$key]['shops'][$userShop['_id']] = [
                                        'category_settings' => [
                                            'barcode_exemption' => true
                                        ]
                                    ];
                                }
                            }
                        }

                        if (!empty($editedData)) {
                            $products[$key]['edited_data'] = $editedData;
                        }
                    }
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }

            $responseData = [
                'success' => true,
                'data' => ['rows' => $products]
            ];

            return $responseData;
        }
        return ['success' => false, 'message' => 'container_id is required.'];
    }

    /**
     * Get Template Data or Default Template Data applied on products.
     *
     * @param $user_id String
     * @param $products Array array of amazon_product_container products or product_container products
     * @param $templates array of templates which needs to be fetched from profile.
     *
     * @return Array It will by default return only 'inventory' & 'pricing' settings of template in following format.
     *
     * [
     *      <container-id-1> => ['inventory' => <inventory-template-data>, 'pricing' => <price-template-data>],
     *      <container-id-2> => ['inventory' => <inventory-template-data>, 'pricing' => <price-template-data>],
     * ]
     *
     */
    private function getProductSettings($user_id, $products, $templates = ['inventory', 'pricing']): array
    {
        $fetchDefaultTemplate = false;
        $profileIds = [];
        $profileNames = [];
        $containerData = [];

        foreach ($products as $product) {
            $containerId = $product['container_id'];

            if (!isset($containerData[$containerId])) {
                if (isset($product['profile']) && !empty($product['profile'])) {
                    foreach ($product['profile'] as $profile) {
                        if (isset($profile['app_tag']) && $profile['app_tag'] == 'amazon') {
                            $containerData[$containerId] = $profile['profile_id'];
                            $profileIds[] = $profile['profile_id'];
                            $profileNames[$profile['profile_id']] = $profile['profile_name'];
                        }
                    }
                } elseif (isset($product['profile_id']) && !in_array($product['profile_id'], $profileIds)) {
                    $containerData[$containerId] = $product['profile_id'];
                    $profileIds[] = $product['profile_id'];
                    $profileNames[$product['profile_id']] = $product['profile_name'];
                } else {
                    $containerData[$containerId] = 'not-profiled';
                    $fetchDefaultTemplate = true;
                }
            }
        }

        $profilesData = $this->getProfileDataFromId($profileIds, $templates);

        if (!empty($profilesData)) {
            $this->setBrowseNodeNameFromId($profilesData);
        }

        $defaultTemplate = [];
        if ($fetchDefaultTemplate) {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $configuration = $mongo->getCollectionForTable(Helper::CONFIGURATION)->findOne(['user_id' => $user_id], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

            if (isset($configuration['data']['inventory_settings'])) {
                if (isset($configuration['data']['inventory_settings']['settings_enabled']) && $configuration['data']['inventory_settings']['settings_enabled'] === true) {
                    $defaultTemplate['inventory'] = $configuration['data']['inventory_settings'];
                }
            }

            if (isset($configuration['data']['pricing_settings'])) {
                if (isset($configuration['data']['pricing_settings']['settings_enabled']) && $configuration['data']['pricing_settings']['settings_enabled'] === true) {
                    $defaultTemplate['pricing'] = $configuration['data']['pricing_settings'];
                }
            }
        }

        $productSettings = [];
        foreach ($containerData as $container_id => $profile_id) {
            if ($profile_id == 'not-profiled') {
                $productSettings[$container_id] = $defaultTemplate;
                $productSettings[$container_id]['profile_id'] = 'default';
                $productSettings[$container_id]['profile_name'] = 'default';
            } elseif (isset($profilesData[$profile_id])) {
                $productSettings[$container_id] = $profilesData[$profile_id];
                $productSettings[$container_id]['profile_id'] = $profile_id;
                $productSettings[$container_id]['profile_name'] = $profileNames[$profile_id];
            }
        }

        return $productSettings;
    }

    public function getAmazonCurrency($user_id)
    {
        if ($user_id) {
            $helper = $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonebaymultiHelper');
            $accounts = $helper->getAllConnectedAcccounts($user_id);
            if ($accounts['success']) {
                $data = $accounts['data'];
                if ($data) {
                    $amazonCurrency = '';

                    foreach ($data as $account) {
                        if ($account['marketplace'] == 'amazon' && $account['warehouses'][0]['status'] == 'active') {
                            $amazonCurrency = Helper::MARKETPLACE_CURRENCY[$account['warehouses'][0]['marketplace_id']];
                            break;
                        }
                    }

                    return $amazonCurrency;
                }
            }
        } else {
            return false;
        }
    }

    public function updateProductCategory($userId, $containerId, $sourceProductId, $groupId, $amazonSellerIds, $categorySettings)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

        $containerData = [];

        if (empty($groupId)) {
            if ($containerId == $sourceProductId) {
                unset($categorySettings['attributes_mapping']);
            }
        } else {
            $containerData['group_id'] = $groupId;
        }

        if (!isset($categorySettings['barcode_exemption'])) {
            $categorySettings['barcode_exemption'] = false;
        }

        if (!empty($amazonSellerIds)) {
            foreach ($amazonSellerIds as $amazonSellerId) {
                $containerData['shops'][$amazonSellerId] = ['category_settings' => $categorySettings];
            }
        }

        if (!empty($containerData)) {
            $containerData['user_id'] = $userId;
            $containerData['container_id'] = $containerId;

            $product = [
                'target_marketplace' => 'amazon',
                'source_product_id'  => $sourceProductId,
                'edited'             => $containerData
            ];

            $products = new ProductContainer();
            return $products->editedProduct($product);
        }

        return ['success' => true];
    }

    public function getAmazonListing($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);

        if (isset($params['data']['user_id'])) {
            $userId = (string)$params['data']['user_id'];
        } else {
            $userId = (string)$this->di->getUser()->id;
        }

        $target_shop_id = (string)$params['target']['shopId'] ?? '';
        $source_shop_id = (string)$params['source']['shopId'] ?? '';
        $source_marketplace = $params['source']['marketplace'];
        if ($target_shop_id == '' || $source_shop_id == '') return ['success' => false, 'message' => 'target_shop_shop or source_shop_id is missing.'];

        //get amazon product by seller-sku
        if (isset($params['data']['seller-sku'])) {
            $sellerSkus = array_values($params['data']['seller-sku']);
            $failedOrderAggregation[] = ['$match' => ['user_id' => $userId, 'shop_id' => $target_shop_id, 'seller-sku' => [
                '$in' => $sellerSkus
            ]]];
            $response = $collection->aggregate($failedOrderAggregation)->toArray();
            $productHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\ProductHelper::class);
            $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
            foreach ($response as $res) {
                if (isset($res['source_product_id']) && !empty($res['source_product_id'])) {
                    $resShopifyProduct = $productCollection->findOne([
                        'user_id' => $userId,
                        // 'source_shop_id' => $source_shop_id,
                        'source_product_id' => (string)$res['source_product_id'],
                        'target_marketplace' => 'amazon',
                        'shop_id' => $target_shop_id,
                        'target_listing_id' => ['$exists' => true]
                    ]);
                    if (empty($resShopifyProduct)) {
                        $manualUnmapParams = [
                            'data' => [
                                'source_product_id' => $res['source_product_id'],
                                'user_id' => $userId,
                                'createOrder' => true
                            ],
                            'source' => [
                                'shopId' => $source_shop_id,
                                'marketplace' => $source_marketplace
                            ],
                            'target' => [
                                'shopId' => $target_shop_id,
                                'marketplace' => 'amazon'
                            ]
                        ];
                        $productHelper->manualUnmap($manualUnmapParams);
                    }
                }
            }

            //temporary fix for client issues dated 31/01/2023 by anjali
            $response = $collection->aggregate($failedOrderAggregation)->toArray();
            return ['success' => true, 'total_products' => count($response), 'response' => $response];
        }

        $limit = $params['data']['count'] ?? ProductContainer::$defaultPagination ?? 10;
        $page = $params['data']['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;
        $aggregation = [];
        $fulfillmentFilter = [];
        if (isset($params['fulfillment-channel']) && $params['fulfillment-channel'] != 'all') {
            if ($params['fulfillment-channel'] != 'DEFAULT' && $params['fulfillment-channel'] != 'FBM') {
                $fulfillmentFilter = ['fulfillment-channel' => ['$ne' => 'DEFAULT']];
            } else {
                $fulfillmentFilter = ['fulfillment-channel' => 'DEFAULT'];
            }
        }

        if (isset($params['data']['matched']) && $params['data']['matched'] != 'all') {
            if ($params['data']['matched'] == 'true') {
                $query = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'matched' => true];
            } elseif ($params['data']['matched'] == 'false') {
                $query = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'matched' => null];
            } elseif ($params['data']['matched'] == 'closeMatch') {
                $query = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'closeMatchedProduct' => ['$exists' => true]];
            } elseif ($params['data']['matched'] == 'deleted') {
                $query = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => 'Deleted'];
            }
        }

        if (!isset($query)) {
            $query = ['user_id' => $userId, 'shop_id' => $target_shop_id];
        }

        $aggregation[] = ['$match' => array_merge($query, $fulfillmentFilter)];

        if (isset($params['sortBy'])) {
            if ($params['sortBy'] == "A-Z" || $params['sortBy'] == "Z-A") {
                $order = ($params['sortBy'] == "A-Z") ? 1 : -1;
                $aggregation[] = [
                    '$addFields' => [
                        'lowercaseField' => ['$toLower' => '$item-name'],
                    ],
                ];
                $aggregation[] = [
                    '$sort' => ['lowercaseField' => $order],
                ];
                $aggregation[] = [
                    '$project' => [
                        'lowercaseField' => 0,
                    ],
                ];
            } else {
                $aggregation[] = ['$match' => ['status' => $params['sortBy']]];
            }
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));
        $countAggregation = $aggregation;
        $countAggregation[]['$count'] = 'listingCount';
        $amazonCounts = $collection->aggregate($countAggregation)->toArray();
        $amazonCount = json_decode(json_encode($amazonCounts), true);
        $amazonCount = $amazonCount[0]['listingCount'] ?? 0;
        if ($offset > 0) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit];
        $response = $collection->aggregate($aggregation)->toArray();
        $ids = array_column($response, 'source_product_id');
        if ($ids !== []) {
            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $option = [
                'projection' => ['_id' => 0, 'sku' => 1, 'source_product_id' => 1, 'main_image' => 1, 'barcode' => 1, 'title' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            $query = ['user_id' => $userId, 'shop_id' => $source_shop_id, 'source_marketplace' => $source_marketplace, 'source_product_id' => ['$in' => $ids]];
            $sourceProducts = $helper->getproductbyQuery($query, $option);
            $productSku = [];
            foreach ($sourceProducts as $product) {
                $productSku[$product['source_product_id']] = $product;
            }

            foreach ($response as $key => $value) {
                if (isset($value['source_product_id']) && isset($productSku[$value['source_product_id']])) {
                    $response[$key]['matchedProduct'] = $productSku[$value['source_product_id']];
                }
            }
        }

        return ['success' => true, 'data' => $aggregation, 'total_products' => $amazonCount, 'response' => $response, 'activePage' => $page];
    }

    public function getListingCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::AMAZON_LISTING)->getPhpCollection();

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            if (isset($params['matched']) && $params['matched'] != 'all') {
                if ($params['matched'] == 'true') {
                    $match = true;
                }

                if ($params['matched'] == 'false') {
                    $match = false;
                }

                $aggregation[] = ['$match' => ['user_id' => $userId, 'shop_id' => $params['shop_id'] ?? '', 'matched' => $match]];
            } else {
                $aggregation[] = ['$match' => ['user_id' => $userId, 'shop_id' => $params['shop_id'] ?? '']];
            }
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params, 'productCount'));
        $aggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($aggregation)->toArray();
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()/*,'data' => $aggregation*/];
        }

        $responseData = [
            'success' => true,
            'query' => $aggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }


    public function getVariants($params)
    {
        //not in use for now
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $limit = $params['count'] ?? ProductContainer::$defaultPagination ?? 10;
        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        if (isset($params['target']['shopId']) && isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
            $target_shop_id = $params['target']['shopId'];
        } else {
            return ['success' => false, 'message' => 'Shop Id is missing.'];
        }

        $params['user_id'] = $userId;

        $aggregation = [];

        if (isset($params['type'])) {

            $type = $params['type'];
        } else {

            $type = 'simple';
        }


        $aggregation[] = ['$match' => [
            'user_id' => $userId,
            'shop_id' => $source_shop_id,
            '$and' => [
                [
                    'type' => $type
                ],
                [
                    '$or' => [
                        [
                            'marketplace.shop_id' => ['$ne' => $target_shop_id]
                        ],
                        [
                            'marketplace' => [
                                '$elemMatch' => [
                                    'shop_id' => $target_shop_id,
                                    '$or' => [
                                        ['status' => null],
                                        ['status' => ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Submitted']]]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]];
        // $aggregation[] = ['$match' => [
        //     'user_id' => $userId, 'shop_id' => $source_shop_id,
        //     '$and' => [
        //         [
        //             'type' => "simple"
        //         ],
        //         [
        //             'marketplace.target_listing_id' => ['$exists' => false]

        //         ],
        //         [
        //             '$or' => [
        //                 ["marketplace.$.status" => ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Submitted']]],
        //                 ["marketplace.$.status" => ['$exists' => false]]
        //             ]
        //         ]
        //     ]
        // ]];
        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit];


        $response = $collection->aggregate($aggregation)->toArray();

        return ['success' => true, 'data' => $response, 'query' => $aggregation];
    }

    public function TokenUpdated($data)
    {
        try {
            if (!empty($data['shop']) && !empty($data['shop']['warehouses']) && !empty($data['shop']['sources'])) {
                $shop = $data['shop'];
                $uniqueKey = $shop['warehouses'][0]['region'] . "_" . $shop['warehouses'][0]['seller_id'] ?? '';
                $shops = $this->di->getUser()->shops;
                $targetShopIds = [];
                //Removing banner for all shop having same region and seller_id
                foreach ($shops as $shopData) {
                    if ($shopData['marketplace'] == Helper::TARGET && !empty($shopData['warehouses'][0])) {
                        $subUniqueKey = $shopData['warehouses'][0]['region'] . "_" . $shopData['warehouses'][0]['seller_id'];
                        if ($uniqueKey == $subUniqueKey) {
                            $targetShopIds[] = $shopData['_id'];
                            $this->removeSpapiErrorFromDynamo($shop['remote_shop_id']);
                        }
                    }
                }
                if (!empty($targetShopIds)) {
                    $sourceShopId = $shop['sources'][0]['shop_id'];
                    $sourceMarketplace = $shop['sources'][0]['code'];
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $config = $mongo->getCollectionForTable('config');
                    $config->updateMany(
                        [
                            'user_id' => $this->di->getUser()->id,
                            'group_code' => 'feedStatus',
                            'key' => 'accountStatus',
                            'target' => Helper::TARGET,
                            'source_shop_id' => $sourceShopId,
                            'target_shop_id' => ['$in' => $targetShopIds],
                            'error_code' => 'invalid_grant',
                        ],
                        [
                            '$set' => [
                                'value' => 'active',
                                'updated_at' => date('c')
                            ]
                        ]
                    );
                    $this->di->getObjectManager()->get('\App\Amazon\Components\Shop\Hook')
                        ->updateAllShopsWarehouseStatus($targetShopIds, 'active');

                    foreach ($targetShopIds as $shopId) {
                        $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                            ->initiateLastFailedSyncAction($this->di->getUser()->id, $shopId, $sourceMarketplace);
                    }
                }

                return ['success' => true, 'message' => 'Warehouse status active and removed token expired banner!!'];
            } else {
                $message = 'Empty shop warehouses or sources';
            }
            return ['success' => true, 'message' => $message ?? 'Something went wrong'];
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Exception TokenUpdated(), Error: ' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    private function removeSpapiErrorFromDynamo($id)
    {
        try {
            $marshaler = new Marshaler();
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            $tableName = 'SPAPI_Error';
            $dynamoClientObj->deleteItem([
                'TableName' => $tableName,
                'Key' => $marshaler->marshalItem([
                    'remote_shop_id'  => (string)$id,
                    'error_code' => 'invalid_grant'
                ])
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception, removeSpapiErrorFromDynamo(), Error: ' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function getVariantsIncludingLinked($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainer = $mongo->getCollectionForTable('refine_product');
        $limit = $params['count'];
        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $userId = $params['data']['user_id'] ?? $this->di->getUser()->id;

        if (!isset($params['target']['shopId']) || !isset($params['source']['shopId'])) {
            return ['success' => false, 'message' => 'source and target shopId is required.'];
        }

        $sourceShopId = $params['source']['shopId'];
        $targetShopId = $params['target']['shopId'];

        $params['user_id'] = $userId;

        $aggregation = [];

        $type = $params['type'] ?? 'simple';

        $aggregation[] = [
            '$match' => [
                'user_id' => $userId,
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId
            ]
        ];

        $aggregation[] = [
            '$unwind' => [
                'path' => '$items'
            ]
        ];
        if ($type == 'simple') {
            $aggregation[] =    [
                '$match' => [
                    '$or' => [
                        [
                            'type' => 'simple'
                        ],
                        [
                            'type' => 'variation',
                            '$expr' => [
                                '$and' => [
                                    [
                                        '$not' => [
                                            '$eq' => [
                                                '$source_product_id',
                                                '$items.source_product_id'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        } else {
            $aggregation[] =  [
                '$match' => [
                    'type' => 'variation',
                    '$expr' => [
                        '$and' => [
                            [
                                '$eq' => [
                                    '$source_product_id',
                                    '$items.source_product_id'
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));
        $aggregationCount = array_merge($aggregation, $this->buildAggregateQuery($params));
        $aggregationCount[] = [
            '$count' => 'count'
        ];
        $count = $productContainer->aggregate($aggregationCount)->toArray();

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit];

        $response = $productContainer->aggregate($aggregation)->toArray();

        return ['success' => true, 'data' => $response, 'query' => $aggregation, 'count' => $count];
    }

    public function getVariantsCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];
        if (isset($params['target']['shopId']) && isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
            $target_shop_id = $params['target']['shopId'];
        } else {
            return ['success' => false, 'message' => 'Shop Id is missing.'];
        }

        if ($type != 'admin') {
            //            $aggregation[] = ['$match' => ['user_id' => $userId, 'type' => "simple", "marketplace.amazon.status" => ['$exists' => false]]];
            $aggregation[] = ['$match' => [
                'user_id' => $userId,
                'shop_id' => $source_shop_id,
                '$and' => [
                    [
                        'type' => "simple"
                        // '$or' => [
                        //     ['group_id' => ['$exists' => true]], ['type' => "simple"]
                        // ]
                    ],
                    [
                        'marketplace.target_listing_id' => ['$exists' => false]
                        // '$or' => [
                        //     ['marketplace.target_marketplace' => ['$ne' => 'amazon']], ['marketplace.shop_id' => ['$ne' => $target_shop_id]]
                        // ]
                    ],
                    [
                        '$or' => [
                            ["marketplace.$.status" => ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Submitted']]],
                            ["marketplace.$.status" => ['$exists' => false]]
                        ]
                    ]
                ]
            ]];
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params, 'productCount'));
        $aggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($aggregation)->toArray();
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()/*,'data' => $aggregation*/];
        }

        $responseData = [
            'success' => true,
            'query' => $aggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }

    /*
    * Parent Action: ProductController-> manualUnmapAction
    * Functionality: Edit a Product from Grid and unmap any variant / simple product
    */
    // public function manualUnmap($params)
    // {
    //     $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //     if (isset($params['user_id'])) {
    //         $userId = $params['user_id'];
    //     } else {
    //         $userId = $this->di->getUser()->id;
    //     }

    //     // Database Tabel Initialization using Models
    //     $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //     $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
    //     $amazonProductCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
    //     $amazonCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);

    //     $shopId = $params['shop_id'];
    //     $sourceProductId = $params['source_product_id']; //source variant id in amazon_listings.

    //     //Fetch Amazon Shop Details by Selected Shop Id
    //     $shop = $user_details->getShop($shopId, $userId);
    //     $seller_id = $shop['warehouses'][0]['seller_id'];

    //     $mappedData = $amazonCollection->findOne(['user_id' => $userId, 'source_variant_id' => $sourceProductId, 'matched' => true]); // check if selected variant is mapped or not.

    //     if ($mappedData) {
    //         $containerId = $mappedData['source_product_id'];
    //         // revert mapped attributes from product_container table
    //         $productQuery = ['user_id' => $userId, 'source_product_id'  => $sourceProductId, 'container_id' => $containerId, 'marketplace.amazon.shop_id' => (string)$shopId];
    //         $productCollection->updateOne($productQuery, ['$unset' => ['marketplace.amazon.$.status' => 1, 'marketplace.amazon.$.seller_id' => 1]]);

    //         // revert mapped attributes from amazon_product_container table
    //         $amazonProductQuery = ['user_id' => $userId, 'source_product_id'  => $sourceProductId, 'container_id' => $containerId];
    //         $amazonProductCollection->updateOne($amazonProductQuery, ['$unset' => ['sku' => 1, 'asin' => 1]]);

    //         //revert from amazon_listing table -> sets as unmatched product
    //         $amazonQuery = ['user_id' => $userId, 'source_variant_id'  => $sourceProductId, 'source_product_id' => $containerId];
    //         $amazonCollection->updateOne($amazonQuery, ['$unset' => ['source_product_id' => 1, 'source_variant_id' => 1, 'matched' => 1, 'manual_mapped' => 1]]);

    //         return ['success' => true, 'message' => 'Shopify SKU unmapped with assigned Amazon ASIN.'];
    //     }
    //     return ['success' => true, 'message' => 'No mapping found for selected variant product.'];
    // }

    public function getVariant($params)
    {
        if (isset($params['source_product_id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();

            $userId = $this->di->getUser()->id;

            try {
                $filter = ['user_id' => $userId, 'source_product_id' => $params['source_product_id']];
                $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

                $variant = $collection->findOne($filter, $options);

                if ($variant) {
                    $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
                    $amazonVariant = $amazonCollection->findOne($filter, ['projection' => ['_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']]);

                    $settings = $this->getProductSettings($userId, [$variant]);

                    $cId = $variant['container_id'];
                    if (array_key_exists('quantity', $variant)) {
                        if (isset($settings[$cId]['inventory'])) {
                            $inv_setting = $settings[$cId]['inventory'];
                            if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                                $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                                $calculatedInv = $productInventory->init()->calculateNew($variant, $inv_setting);
                                if (isset($calculatedInv['Quantity'])) {
                                    $variant['quantity'] = $calculatedInv['Quantity'];
                                }
                            }
                        }
                    }

                    if (array_key_exists('price', $variant)) {
                        if (isset($settings[$cId]['pricing'])) {
                            $productPrice = $this->di->getObjectManager()->get(Price::class);
                            $conversion_rate = $productPrice->init()->getConversionRate();
                            if ($conversion_rate === false) $conversion_rate = 1;

                            $price_setting = $settings[$cId]['pricing'];
                            if (isset($price_setting['settings_enabled']) && $price_setting['settings_enabled']) {
                                $priceList = $productPrice->init()->calculateNew($variant, $price_setting, $conversion_rate);
                                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                    $variant['price'] = $priceList['StandardPrice'];
                                }
                            } else {
                                $priceList = $productPrice->init()->calculateNew($variant, Helper::DEFAULT_PRICE_SETTING, $conversion_rate);
                                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                    $variant['price'] = $priceList['StandardPrice'];
                                }
                            }
                        } else {
                            $productPrice = $this->di->getObjectManager()->get(Price::class);
                            $conversion_rate = $productPrice->init()->getConversionRate();
                            if ($conversion_rate === false) $conversion_rate = 1;

                            $priceList = $productPrice->init()->calculateNew($variant, Helper::DEFAULT_PRICE_SETTING, $conversion_rate);
                            if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                $variant['price'] = $priceList['StandardPrice'];
                            }
                        }

                        if ($currencyCode = $this->getAmazonCurrency($userId)) {
                            $variant['price_with_currency'] = "{$variant['price']} {$currencyCode}";
                            $variant['amazon_currency'] = $currencyCode;
                        }
                    }

                    $editedData = [];

                    if ($amazonVariant) {
                        $editedData = $amazonVariant;
                    }

                    if (isset($settings[$variant['container_id']])) {
                        $editedData['profile_data'] = $settings[$variant['container_id']];

                        if (!isset($variant['profile_id'])) {
                            $editedData['profile_id'] = $settings[$variant['container_id']]['profile_id'] ?? 'default';
                            $editedData['profile_name'] = $settings[$variant['container_id']]['profile_name'] ?? 'default';
                        }
                    }

                    if (!empty($editedData)) {
                        $variant['edited_data'] = $editedData;
                    }

                    return ['success' => true, 'data' => $variant];
                }
                return ['success' => false, 'message' => 'variant not found.'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        } else {
            return ['success' => false, 'message' => 'source_product_id is required.'];
        }
    }

    /*public function saveVariant($rawBody)
    {
        try {
            $response = [];

            if (isset($rawBody['target_marketplace'])) {
                if (isset($rawBody['edited_details'], $rawBody['container_id'])) {

                    $edited_details = $rawBody['edited_details'];
                    $containerId = $rawBody['container_id'];

                    if (isset($rawBody['variant_attributes'], $rawBody['variants'])) {

                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $marketplaceProductCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);

                        $failed_response = [];
                        $response = ['success' => true, 'message' => 'Product saved successfully.'];

                        $resp = $this->saveProductContainer($rawBody);
                        if ($resp['success'] === false) {
                            $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                            $failed_response[] = $resp;
                        }

                        // SAVE VARIATIONS
                        $originalDataArray = [];
                        $items = $marketplaceProductCollection->find(['container_id' => (string)$containerId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                        if ($items) {
                            foreach ($items as $item) {
                                if (isset($item['group_id'])) {
                                    $originalDataArray[$item['source_product_id']] = $item;
                                }
                            }
                        }

                        $availableSourceProductIds = [];

                        foreach ($rawBody['variants'] as $variant) {
                            $availableSourceProductIds[] = $variant['source_product_id'];

                            $filterData = [
                                'source_product_id' => (string)$variant['source_product_id']
                            ];

                            $selectFields = [
                                'projection' => ['_id' =>  0],
                                'typeMap'   => ['root' => 'array', 'document' => 'array']
                            ];

                            // $originalData = $marketplaceProductCollection->findOne($filterData, $selectFields);
                            // if(!$originalData) {
                            //     $originalData = [];
                            // }

                            $originalData = $originalDataArray[$variant['source_product_id']] ?? [];

                            $editedData = $unset = [];

                            if (array_key_exists('edit_variants', $edited_details) && $edited_details['edit_variants']) {
                                // $editedData = array_merge($editedData, $variant);
                                if (array_key_exists('is_edited_price', $variant) && $variant['is_edited_price']) {
                                    $editedData['price'] = $variant['price'];
                                } elseif (array_key_exists('price', $originalData)) {
                                    $unset['price'] = 1;
                                }

                                if (array_key_exists('is_edited_quantity', $variant) && $variant['is_edited_quantity']) {
                                    $editedData['quantity'] = $variant['quantity'];
                                } elseif (array_key_exists('quantity', $originalData)) {
                                    $unset['quantity'] = 1;
                                }

                                if (array_key_exists('is_edited_sku', $variant) && $variant['is_edited_sku']) {
                                    $editedData['sku'] = str_replace(' ', '', $variant['sku']);
                                } elseif (array_key_exists('sku', $originalData)) {
                                    $unset['sku'] = 1;
                                }

                                if (array_key_exists('is_edited_barcode', $variant) && $variant['is_edited_barcode']) {
                                    $editedData['barcode'] = $variant['barcode'];

                                    // $barcode = $this->di->getObjectManager()->create('App\Amazon\Components\Common\Barcode');
                                    // $validateBarcode = $barcode->setBarcode($variant['barcode']);
                                    // if($validateBarcode === false) {
                                    //     return ['success' => false, 'message' => "Barcode is invalid for SKU : {$variant['sku']}. Please enter valid barcode in all the variants."];
                                    // }
                                } elseif (array_key_exists('barcode', $originalData)) {
                                    $unset['barcode'] = 1;
                                }
                            }

                            if (array_key_exists('title', $edited_details)) {
                                if (is_array($edited_details['title']) && in_array('custom', $edited_details['title'])) {
                                    $editedData['title'] = $rawBody['title'];
                                } else {
                                    $unset['title'] = 1;
                                }
                            } elseif (array_key_exists('title', $originalData)) {
                                $unset['title'] = 1;
                            }
                            // if(array_key_exists('title', $edited_details) && $edited_details['title']) {
                            //     $editedData['title'] = $rawBody['title'];
                            // }
                            // elseif (array_key_exists('title', $originalData)) {
                            //     $unset['title'] = 1;
                            // }

                            if (array_key_exists('description', $edited_details)) {
                                if (is_array($edited_details['description']) && in_array('custom', $edited_details['description'])) {
                                    $editedData['description'] = $rawBody['description'];
                                } else {
                                    $unset['description'] = 1;
                                }
                            } elseif (array_key_exists('description', $originalData)) {
                                $unset['description'] = 1;
                            }
                            // if(array_key_exists('description', $edited_details) && $edited_details['description']) {
                            //     $editedData['description'] = $rawBody['description'];
                            // }
                            // elseif (array_key_exists('description', $originalData)) {
                            //     $unset['description'] = 1;
                            // }

                            if (array_key_exists('inventory_fulfillment_latency', $edited_details)) {
                                if (is_array($edited_details['inventory_fulfillment_latency']) && in_array('custom', $edited_details['inventory_fulfillment_latency'])) {
                                    $editedData['inventory_fulfillment_latency'] = $rawBody['inventory_fulfillment_latency'];
                                } else {
                                    $unset['inventory_fulfillment_latency'] = 1;
                                }
                            } elseif (array_key_exists('inventory_fulfillment_latency', $originalData)) {
                                $unset['inventory_fulfillment_latency'] = 1;
                            }
                            // if(array_key_exists('inventory_fulfillment_latency', $edited_details) && $edited_details['inventory_fulfillment_latency']) {
                            //     $editedData['inventory_fulfillment_latency'] = $rawBody['inventory_fulfillment_latency'];
                            // }
                            // elseif (array_key_exists('inventory_fulfillment_latency', $originalData)) {
                            //     $unset['inventory_fulfillment_latency'] = 1;
                            // }

                            // if(array_key_exists('parent_sku', $edited_details) && $edited_details['parent_sku']) {
                            //     $editedData['parent_sku'] = $rawBody['parent_sku'];
                            // }
                            // elseif (array_key_exists('parent_sku', $originalData)) {
                            //     $unset['parent_sku'] = 1;
                            // }

                            if ((!empty($rawBody['amazon_seller_id']) || isset($edited_details['amazon_seller_ids_array']))) {
                                $cat_data = [];

                                $optional_attribute_field_name = 'optional_fields';
                                if (isset($variant[$optional_attribute_field_name])) {
                                    // $cat_data['optional_attributes'] = array_filter($variant[$optional_attribute_field_name]);
                                    $cat_data['optional_attributes'] = $variant[$optional_attribute_field_name];
                                }

                                if (isset($rawBody['variation_theme_value'])) {
                                    $cat_data['variation_theme_attribute_name'] = $rawBody['variation_theme_value'];
                                }

                                if (isset($variant['variation_theme_fields'])) {
                                    $cat_data['variation_theme_attribute_value'] = $variant['variation_theme_fields'];
                                }

                                if (array_key_exists('assign_category', $edited_details) && is_array($edited_details['assign_category']) && in_array('custom', $edited_details['assign_category'])) {
                                    $cat_data['category_settings'] = $rawBody['category_settings'];
                                } else {
                                    $cat_data['category_settings'] = ['barcode_exemption' => $rawBody['category_settings']['barcode_exemption'] ?? false];
                                }

                                if (!empty($cat_data)) {
                                    if (isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                                        foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                                            $editedData['shops'][$amazonSellerId['id']] = $cat_data;
                                        }
                                    } else {
                                        $editedData['shops'][$rawBody['amazon_seller_id']] = $cat_data;
                                    }
                                } else {
                                    $unset['shops'] = 1;
                                }
                            }
                            // if((!empty($rawBody['amazon_seller_id']) || isset($edited_details['amazon_seller_ids_array'])) && array_key_exists('assign_category', $edited_details))
                            // {
                            //     if($edited_details['assign_category'])
                            //     {
                            //         if(isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                            //             foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                            //                 $editedData['shops'][$amazonSellerId['id']] = ['category_settings' => $rawBody['category_settings']];
                            //             }
                            //         } else {
                            //             $editedData['shops'][$rawBody['amazon_seller_id']] = ['category_settings' => $rawBody['category_settings']];
                            //         }
                            //     }
                            //     else
                            //     {
                            //         if(isset($edited_details['amazon_seller_ids_array']) && is_array($edited_details['amazon_seller_ids_array'])) {
                            //             // foreach ($edited_details['amazon_seller_ids_array'] as $amazonSellerId) {
                            //             //     if(isset($originalData['shops']) && array_key_exists($rawBody['amazon_seller_id'], $originalData['shops'])) {
                            //             //         $unset["shops.{$amazonSellerId['id']}"] = 1;
                            //             //     }
                            //             // }
                            //             $unset['shops'] = 1;
                            //         } else {
                            //             // if(isset($originalData['shops']) && array_key_exists($rawBody['amazon_seller_id'], $originalData['shops'])) {
                            //             //     $unset["shops.{$rawBody['amazon_seller_id']}"] = 1;
                            //             // }
                            //             $unset['shops'] = 1;
                            //         }
                            //     }
                            // }

                            if (count($rawBody['variant_attributes']) > 0) {
                                if (!isset($originalData['group_id'])) {
                                    $editedData['group_id'] = $containerId;
                                }
                            } elseif (isset($originalData['group_id'])) {
                                $unset['group_id'] = 1;
                            }

                            if (!empty($unset)) {
                                $marketplaceProductCollection->updateOne($filterData, ['$unset' => $unset]);
                            }

                            if (!empty($editedData)) {
                                $editedData['user_id'] = $this->di->getUser()->id;
                                $editedData['container_id'] = $containerId;

                                $product = [
                                    'target_marketplace' => $rawBody['target_marketplace'],
                                    'source_product_id'  => $variant['source_product_id'],
                                    'edited'             => $editedData
                                ];

                                $products = new \App\Connector\Models\ProductContainer();
                                $resp = $products->editedProduct($product);

                                if ($resp['success'] === false) {
                                    $response = ['success' => false, 'message' => 'some products(s) failed to save'];
                                    $failed_response[] = $resp;
                                }
                            }
                        }

                        $deleteSourceProIds = [];
                        foreach ($originalDataArray as $sourceProductId => $value) {
                            if (!in_array($sourceProductId, $availableSourceProductIds)) {
                                $deleteSourceProIds[] = (string)$sourceProductId;
                            }
                        }

                        if (!empty($deleteSourceProIds)) {
                            $marketplaceProductCollection->deleteMany([
                                'user_id' => $this->di->getUser()->id,
                                'source_product_id' => ['$in' => $deleteSourceProIds]
                            ], ['w' => true]);
                        }
                        // SAVE VARIATIONS

                        if (!empty($failed_response)) {
                            $response['failed'] = $failed_response;
                        }

                        $validate = $this->validateAmazonProduct($rawBody);
                        if ($validate['success'] === false) {
                            return $validate;
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'variant_attributes & variants is required.'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'edited_details & container_id is required.'];
                }
            } else {
                $response = ['success' => false, 'message' => 'target_marketplace is required.'];
            }

            return $response;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }*/

    public function getProductsNew($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? ProductContainer::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode((string) $params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage']) && is_numeric($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $userId = $this->di->getUser()->id;

        $query = $this->buildAggregateQueryNew($params);

        if ($query) {
            $filterApplied = false;
            $universal_filter_fields = ['user_id', 'app_codes'];

            foreach ($query as $field => $fieldValue) {
                if (in_array($field, $universal_filter_fields)) {
                    continue;
                }

                $filterApplied = true;
                break;
            }
        }

        if ($filterApplied) {
            $filteredContainerIds = $this->getFilteredContainerIds($userId, $query);
            $filteredContainerIdsChunk = array_chunk($filteredContainerIds, $limit);

            $page ??= 1;
            $index = $page - 1;
            $distinctContainerIds = $filteredContainerIdsChunk[$index] ?? [];
        } else {
            $this->resetListingFilterCache($userId);

            $query['visibility'] = 'Catalog and Search';

            $options = [
                'typeMap'   => ['root' => 'array', 'document' => 'array'],
                'projection' => ['container_id' => 1],
                'limit'    => $limit + 1
            ];

            if (isset($offset)) {
                $options['skip'] = $offset;
            }

            $containerIds = $collection->find($query, $options)->toArray();

            $distinctContainerIds = array_column($containerIds, 'container_id');
        }

        $productStatuses = $this->prepareProductsStatusNew($distinctContainerIds);
        $rows = array_values($productStatuses);

        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null
            ]
        ];

        return $responseData;
    }

    public function buildAggregateQueryNew($params, $callType = 'getProduct')
    {
        $userId = $this->di->getUser()->id;

        $aggregation = [];

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        if (isset($params['filter']['variant_attributes'])) {
            $temp = [];
            if (isset($params['filter']['variant_attributes'][3])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][3]);
            } else if (isset($params['filter']['variant_attributes'][1])) {
                $temp = explode(",", (string) $params['filter']['variant_attributes'][1]);
            }

            unset($params['filter']['variant_attributes']);

            $aggregation[] = ['$match' => ['variant_attributes' =>  ['$eq' => $temp]]];
        }

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        $query = [];
        foreach ($aggregation as $_aggregation) {
            if (isset($_aggregation['$match'])) {
                foreach ($_aggregation['$match'] as $key => $value) {
                    $query[$key] = $value;
                }
            }
        }

        return $query;
    }

    public function getListingFilterCacheName($userId)
    {
        return "listing_filters_string_{$userId}";
    }

    public function getFilteredListingIdsCacheName($userId)
    {
        return "listing_filters_ids_{$userId}";
    }

    public function getFilteredContainerIds($userId, $filters)
    {
        $key_filter = $this->getListingFilterCacheName($userId);
        $key_filter_ids = $this->getFilteredListingIdsCacheName($userId);

        $filter_str = json_encode($filters);

        $cached_filter_str = $this->di->getCache()->get($key_filter);

        if ($cached_filter_str) {
            if ($cached_filter_str == $filter_str) {
                $cached_filter_ids_str = $this->di->getCache()->get($key_filter_ids);

                if ($cached_filter_str) {
                    $cached_filter_ids = explode(',', (string) $cached_filter_ids_str);
                } else {
                    $cached_filter_ids = [];
                }

                return $cached_filter_ids;
            }
            $this->di->getCache()->delete($key_filter);
            $this->di->getCache()->delete($key_filter_ids);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();
            $distinctContainerIds = $collection->distinct('container_id', $filters);
            $this->di->getCache()->set($key_filter, $filter_str);
            $this->di->getCache()->set($key_filter_ids, implode(',', $distinctContainerIds));
            return $distinctContainerIds;
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();
        $distinctContainerIds = $collection->distinct('container_id', $filters);
        $this->di->getCache()->set($key_filter, $filter_str);
        $this->di->getCache()->set($key_filter_ids, implode(',', $distinctContainerIds));
        return $distinctContainerIds;
    }

    public function resetListingFilterCache($userId)
    {
        $key_filter = $this->getListingFilterCacheName($userId);
        $key_filter_ids = $this->getFilteredListingIdsCacheName($userId);

        $this->di->getCache()->delete($key_filter);
        $this->di->getCache()->delete($key_filter_ids);

        return true;
    }

    public function prepareProductsStatusNew($container_ids)
    {
        $status_arr = [];

        $query = ['user_id' => $this->di->getUser()->id];

        $query['container_id'] = ['$in' => $container_ids];

        // $query['visibility'] = 'Catalog and Search';

        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

        // $select_fields = ['user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'marketplace', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'variant_title', 'sku', 'barcode', 'quantity', 'variant_image', 'price'];
        $select_fields = ['user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'variant_title', 'sku', 'barcode', 'quantity', 'variant_image', 'price'];
        foreach ($select_fields as $field) {
            $options['projection'][$field] = 1;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();
        $products = $collection->find($query, $options)->toArray();

        $settings = $this->getProductSettings($this->di->getUser()->id, $products, ['inventory']);
        $getEditedData = $this->getEditedData($container_ids);

        $productInventory = $this->di->getObjectManager()->get(Inventory::class);

        foreach ($products as $product) {
            $containerId = $product['container_id'];

            if (array_key_exists('variant_title', $product) && !empty($product['variant_attributes'])) {
                $variantInfo = [
                    'container_id'      => $product['container_id'],
                    'source_product_id' => $product['source_product_id'],
                    'sku'               => $product['sku'],
                    'variant_title'     => $product['variant_title'],
                    // 'marketplace'       => $product['marketplace'],
                    'type'              => $product['type'],
                    'visibility'        => $product['visibility'],
                    'quantity'          => $product['quantity'],
                    'barcode'           => $product['barcode'],
                    'price'             => $product['price'],
                    'variant_image'     => $product['variant_image']
                ];

                if (isset($getEditedData[$containerId][$product['source_product_id']]['barcode'])) {
                    $variantInfo['barcode'] = $getEditedData[$containerId][$product['source_product_id']]['barcode'];
                }

                if (isset($getEditedData[$containerId][$product['source_product_id']]['sku'])) {
                    $variantInfo['sku'] = $getEditedData[$containerId][$product['source_product_id']]['sku'];
                }

                $amazonDatas = $getEditedData[$containerId][$product['container_source_product_id']];

                if (isset($amazonDatas['parent_sku'])) {
                    $variantInfo['parent_sku'] = $amazonDatas['parent_sku'];
                } else if (isset($amazonDatas['sku'])) {
                    $variantInfo['parent_sku'] = $amazonDatas['sku'];
                }

                if (array_key_exists('quantity', $product)) {
                    if (isset($settings[$containerId]['inventory'])) {
                        $inv_setting = $settings[$containerId]['inventory'];
                        if (isset($inv_setting['settings_enabled']) && $inv_setting['settings_enabled']) {
                            $calculatedInv = $productInventory->init()->calculateNew($product, $inv_setting);
                            if (isset($calculatedInv['Quantity'])) {
                                $variantInfo['quantity'] = $calculatedInv['Quantity'];
                            }
                        }
                    }
                }

                $status_arr[$containerId]['variants'][] = $variantInfo;
            } else {
                $productInfo = [
                    'user_id'           => $product['user_id'],
                    'container_id'      => $product['container_id'],
                    'source_product_id' => $product['source_product_id'],
                    'main_image'        => $product['main_image'],
                    'title'             => $product['title'],
                    'additional_images' => $product['additional_images'],
                    'type'              => $product['type'],
                    'brand'             => $product['brand'],
                    'description'       => $product['description'] ?? '',
                    'product_type'      => $product['product_type'],
                    'visibility'        => $product['visibility'],
                    'app_codes'         => $product['app_codes'],
                    'variant_attributes' => $product['variant_attributes'],
                    'variants'          => []
                ];

                if (!empty($product['profile'])) {
                    $productInfo['profile'] = $product['profile'];
                }

                if (!empty($product['marketplace'])) {
                    $productInfo['marketplace'] = $product['marketplace'];
                }

                if (isset($getEditedData[$containerId][$product['source_product_id']]['barcode'])) {
                    $productInfo['barcode'] = $getEditedData[$containerId][$product['source_product_id']]['barcode'];
                }

                if (isset($getEditedData[$containerId][$product['source_product_id']]['sku'])) {
                    $productInfo['sku'] = $getEditedData[$containerId][$product['source_product_id']]['sku'];
                }

                if (isset($status_arr[$containerId])) {
                    $status_arr[$containerId] = array_merge($productInfo, $status_arr[$containerId]);
                } else {
                    $status_arr[$containerId] = $productInfo;
                }
            }
        }

        return $status_arr;
    }

    public function getStepsDetails($data)
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $targetShopId = $this->di->getRequester()->getTargetId();
        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $user_id = $this->di->getUser()->id;
        $configObj->setUserId($user_id);

        if (!($sourceShopId) || !($app_tag) || !($source)) {
            return ['success' => false, 'message' => 'Source shop or Source or app tag not found'];
        }
        $configObj->sourceSet($source);
        $configObj->setSourceShopId($sourceShopId);
        $configObj->setAppTag($app_tag);
        $configObj->setTargetShopId($targetShopId);
        $data['app_tag'] = $app_tag;

        $res = $configObj->getConfig(Helper::STEP_COMPLETED);
        $res = json_decode(json_encode($res, true), true);
        if (!empty($res) && !empty($targetShopId)) {
            if ($res[0]['value'] == 3) {

                return ['success' => true, 'stepsCompleted' => $res[0]['value'], 'onboarding_completed' => $res[0]['onboarding_completed']];
            }
            return ['success' => true, 'stepsCompleted' => $res[0]['value']];
        }
        return ['success' => true, 'stepsCompleted' => 0];
    }


    public function saveStepsDetails($data)
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();

        $targetShopId = $this->di->getRequester()->getTargetId();

        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();

        if (!($sourceShopId) || !($app_tag) || !($source)) {
            return ['success' => false, 'message' => 'Source shop or Source or app tag not found'];
        }
        $data['source'] = $source;
        $data['app_tag'] = $app_tag;
        $data['source_shop_id'] = $sourceShopId;
        $data['group_code'] = 'onboarding';
        $data['target_shop_id'] = $targetShopId;
        $data['target'] = $target;


        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setUserId();
        $data['key'] = Helper::STEP_COMPLETED;
        $data['value'] = $data['stepsCompleted'];
        if ($data['stepsCompleted'] == 3) {
            $data['onboarding_completed'] = true;
        }

        $res = $configObj->setConfig([$data]);

        return ['success' => true, 'data' => $res];
    }

    public function saveProductSyncingDetails($data)
    {
        $warehouses = [];
        $user_id = $this->di->getUser()->id;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if (!($shops = $this->getUserCache('shops', $user_id))) {
            $shops = $user_details->getUserDetailsByKey('shops', $user_id);
            $this->setUserCache('shops', $shops, $user_id);
        }

        $shops = $shops ?: [];
        foreach ($shops as $shop) {
            if ($shop['marketplace'] === 'shopify') {
                foreach ($shop['warehouses'] as $warehouse) {
                    if ($warehouse['active'] == true && isset($warehouse['name'])) {
                        array_push($warehouses, $warehouse['id']);
                    }
                }
            }
        }

        $defaultValues = ["inventory_settings" => ["settings_enabled" => false, "settings_selected" => ["fixed_inventory" => false, "threshold_inventory" => false, "delete_out_of_stock" => false, "customize_inventory" => false, "inventory_fulfillment_latency" => true, "warehouses_settings" => false], "fixed_inventory" => "", "threshold_inventory" => "", "customize_inventory" => ["attribute" => "default", "value" => ""], "warehouses_settings" => $warehouses, "inventory_fulfillment_latency" => "2"], "pricing_settings" => ["settings_enabled" => false, "settings_selected" => ["sale_price" => false, "business_price" => false, "minimum_price" => false], "standard_price" => ["attribute" => "default", "value" => ""], "sale_price" => ["attribute" => "default", "value" => "", "start_date" => "", "end_date" => ""], "business_price" => ["attribute" => "default", "value" => ""], "minimum_price" => ["attribute" => "default", "value" => ""]], "product_settings" => ["settings_enabled" => false, "enable_syncing" => false, "selected_attributes" => []]];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("configuration");
        $temp = [];
        if ($data['productSyncingDetails']['isImages'] === true) {
            array_push($temp, "images");
            $defaultValues['product_settings']['settings_enabled'] = true;
        }

        if ($data['productSyncingDetails']['isProductDetails'] === true) {
            array_push($temp, "product_syncing");
            $defaultValues['product_settings']['settings_enabled'] = true;
        }

        $defaultValues['inventory_settings']['settings_enabled'] = $data['productSyncingDetails']['isInventory'];
        $defaultValues['pricing_settings']['settings_enabled'] = $data['productSyncingDetails']['isPrice'];
        $defaultValues['product_settings']['selected_attributes'] = $temp;
        $collection->updateOne(
            ["user_id" => $this->di->getUser()->id],
            ['$set' => ["data" => $defaultValues]],
            ['upsert' => true]
        );

        return ['success' => 'true', 'productSyncingDetails' => $data['productSyncingDetails']];
    }

    /*To Migrate the Data - One Time Utilisation of this function
    **
    ** Case 1: Migrate Complete Data based on pagination
    ** Case 2: Migrate Data based on User ID
    */
    public function manualMapMigration($rawBody): void
    {
        die(" remove the comment to proceed further!!");
    }

    public function ShippedOrderFixes($rawBody): void
    {
        $chunk = 50000;
        $page = $rawBody['page'] ?? 1;
        $limit = $chunk * $page;
        $skip = $limit - $chunk;

        echo "<pre>";
        echo $skip;
        echo "//";
        echo $limit;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);

        // $UnShippedOrderCount = $orderCollection->count(['source_status' => 'Unshipped']);
        // echo "<pre>";
        // print_r($UnShippedOrderCount);die();

        // $UnShippedOrdersUsers = $orderCollection->distinct('user_id',['source_status' => 'Unshipped']);
        // echo "<pre>";
        // print_r($UnShippedOrdersUsers);die();

        $aggregation = [
            ['$match' => ['source_status' => 'Unshipped']],
            ['$project' => ['user_id' => 1, 'source_order_id' => 1, 'seller_id' => 1]],
        ];

        // $aggregation[] = ['$skip' => $skip];
        // $aggregation[] = ['$limit' => $limit];

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];

        $orders = $orderCollection->aggregate($aggregation, $options)->toArray();
        $collectUsersperOrders = [];
        foreach ($orders as $order) {
            $collectUsersperOrders[$order['user_id']][] = $order['source_order_id'];
        }

        $collect = [];
        foreach ($collectUsersperOrders as $user => $orders) {
            $collect[$user] = count($orders);
        }

        print_r($collect);
        die();
    }

    /*Function to Fetch Edited Data from Amazon Product Container*/
    public function getEditedData($container_ids)
    {
        $editedArray = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::AMAZON_PRODUCT_CONTAINER)->getPhpCollection();
        $aggregation = [
            ['$match' => ['container_id' => ['$in' => $container_ids]]],
            [
                '$group' => [
                    '_id' => '$container_id',
                    'edited'   => [
                        '$push' => [
                            'source_product_id' => '$source_product_id',
                            'sku'               => '$sku',
                            'parent_sku'               => '$parent_sku',
                            'barcode'     => '$barcode'
                        ]
                    ]
                ]
            ]
        ];
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
        $products = $collection->aggregate($aggregation, $options)->toArray();

        if (!empty($products)) {
            foreach ($products as $productObj) {
                if (isset($productObj['edited']) && !empty($productObj['edited'])) {
                    foreach ($productObj['edited'] as $sourceProductObj) {
                        $editedArray[$productObj['_id']][$sourceProductObj['source_product_id']] = $sourceProductObj;
                    }
                }
            }
        }

        return $editedArray;
    }



    /**
     * manual product linking
     *
     * @param array $params
     * @return array
     */
    public function manualMap($params)
    {
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        $source_shop_id = false;
        $target_shop_id = false;
        if (isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
        }

        if (isset($params['target']['shopId'])) {
            $target_shop_id = $params['target']['shopId'];
        }

        if (!$source_shop_id || !$target_shop_id) {
            return ['success' => false, 'message' => 'Required all params.'];
        }

        $objectManager = $this->di->getObjectManager();
        $mongo = $objectManager->create('\App\Core\Models\BaseMongo');
        $user_details = $objectManager->get('\App\Core\Models\User\Details');
        $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
        $validator = $objectManager->get(SourceModel::class);
        $shop = $user_details->getShop($target_shop_id, $userId);
        $seller_id = $shop['warehouses'][0]['seller_id'];
        $listingData = $amazonCollection->findOne([
            '_id' => new ObjectId($params['data']['_id']),
            'shop_id' => $target_shop_id,
            'matched' => null
        ]);
        if (empty($listingData))
            return ['success' => false, 'message' => 'This amazon product is already matched with shopify product.'];

        $query = ['user_id' => $userId, 'source_product_id' => $params['data']['source_product_id']];
        $productData = $helper->getproductbyQuery($query);

        // Separate source and edited (target) products
        $editedProductData = array_values(array_filter($productData, function ($item) use ($target_shop_id) {
            return isset($item['shop_id']) && $item['shop_id'] == $target_shop_id;
        }));

        $productData = array_values(array_filter($productData, function ($item) use ($source_shop_id) {
            return isset($item['shop_id']) && $item['shop_id'] == $source_shop_id;
        }));
        if (empty($productData))
            return ['success' => false, 'message' => 'This shopify product can not be found.'];
        $testUserId = $this->di->getConfig()->get('betauploadsync')?->toArray() ?? [];
        $val = json_decode(json_encode($productData), true);
        foreach ($val as $productData) {
            if (isset($listingData['type'])) {
                if ($productData['type'] != $listingData['type']) {
                    if ($listingData['type'] == 'simple') {
                        return ['success' => false, 'message' => 'Shopify parent product can not be mapped to Amazon simple/child product.'];
                    }
                    return ['success' => false, 'message' => 'Shopify simple/child product can not be mapped with parent Amazon product.'];
                }
            }

            $marketplaceQuery = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'source_product_id' => $productData['source_product_id'], 'status' => ['$in' =>
            ['Active', 'Inactive', 'Incomplete', 'Submitted']]];
            $marketplaceDoc = $helper->getproductbyQuery($marketplaceQuery);
            if (!empty($marketplaceDoc)) {
                return ['success' => false, 'message' => 'This product is already linked.'];
            }


            //custom code for uploaded product sync
            if (in_array($this->di->getUser()->id, $testUserId)) {
                $UploadedProductSyncHelper = $objectManager->get('\App\Amazon\Components\UploadedProductSyncHelper');
                $productDataFromAmazon = $UploadedProductSyncHelper->getListingData($listingData);
                return $UploadedProductSyncHelper->saveData($productData, $productDataFromAmazon, $listingData, $params);
            }

            // if (isset($productData['marketplace'])) {
            //     foreach ($productData['marketplace'] as $marketplaceData) {
            //         if ($marketplaceData['shop_id'] == $target_shop_id && isset($marketplaceData['status'])) {
            //             if (in_array($marketplaceData['status'], ['Active', 'Inactive', 'Incomplete', 'Submitted'])) {
            //                 return ['success' => false, 'message' => 'This product is already linked.'];
            //             }
            //         }
            //     }
            // }

            if (isset($listingData['fulfillment-channel']) && ($listingData['fulfillment-channel'] != 'DEFAULT' && $listingData['fulfillment-channel'] != 'default')) {
                $channel = 'FBA';
            } else {
                $channel = 'FBM';
            }

            $asin = '';
            if (isset($listingData['asin1']) && $listingData['asin1'] != '') {
                $asin = $listingData['asin1'];
            } elseif (isset($listingData['asin2']) && $listingData['asin2'] != '') {
                $asin = $listingData['asin2'];
            } elseif (isset($listingData['asin3']) && $listingData['asin3'] != '') {
                $asin = $listingData['asin3'];
            }

            $status = $listingData['status'];
            if (isset($listingData['type']) && $listingData['type'] == 'variation') {
                $status = 'parent_' . $listingData['status'];
            }

            if ($asin != '') {
                // update asin on product container
                $asinData = [
                    [
                        'user_id' => $userId,
                        'target_marketplace' => 'amazon',
                        'shop_id' => (string)$target_shop_id,
                        'container_id' => $productData['container_id'],
                        'source_product_id' => $productData['source_product_id'],
                        'asin' => $asin,
                        'sku' => $listingData['seller-sku'],
                        "shopify_sku" => $productData['sku'],
                        'matchedwith' => 'manual',
                        'status' => $status,
                        'target_listing_id' => (string)$listingData['_id'],
                        'source_shop_id' => $source_shop_id,
                        'fulfillment_type' => $channel,
                        'amazonStatus' => $listingData['amazonStatus'],
                        'matched_at' => date('c')
                    ]
                ];
                //validate product for unique sku in marketplace
                $valid = $validator->validateSaveProduct($asinData, $userId, $params);
                if (isset($valid['success']) && !$valid['success']) {
                    return ['success' => false, 'message' => "Product's seller-sku is not unique in marketplace."];
                }

                $editHelper = $objectManager->get('\App\Connector\Models\Product\Edit');
                $editHelper->saveProduct($asinData, $userId, $params);
            }

            // $updateData = [
            //     'source_product_id' => $productData['source_product_id'], // required
            //     'user_id' => $userId,
            //     'source_shop_id' => $source_shop_id,
            //     'container_id' => $productData['container_id'],
            //     'childInfo' => [
            //         'source_product_id' => $productData['source_product_id'], // required
            //         'shop_id' => $target_shop_id, // required
            //         'status' => $status,
            //         'target_marketplace' => 'amazon', // required
            //         'seller_id' => $seller_id,
            //         'asin' => $asin,
            //         'sku' => $listingData['seller-sku'],
            //         'matchedwith' => 'manual',
            //         'target_listing_id' => (string)$listingData['_id']
            //     ],
            // ];
            $updateData = [
                'source_product_id' => $productData['source_product_id'], // required
                'user_id' => $userId,
                'source_shop_id' => $source_shop_id,
                'container_id' => $productData['container_id'],
                'shop_id' => $target_shop_id, // required
                'status' => $status,
                'target_marketplace' => 'amazon', // required
                'seller_id' => $seller_id,
                'asin' => $asin,
                'sku' => $listingData['seller-sku'],
                "shopify_sku" => $productData['sku'],
                'matchedwith' => 'manual',
                'target_listing_id' => (string)$listingData['_id'],
                'matched_at' => date('c')
            ];
            // $helper->marketplaceSaveAndUpdate($updateData);
            $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($updateData, $userId, $params);

            $matchedData = [
                'source_product_id' => $productData['source_product_id'],
                'source_container_id' => $productData['container_id'],
                'matched' => true,
                'manual_mapped' => true, //Identifies SKU/Barcode/Title gets mapped manually.
                'matchedwith' => 'manual', //Identifies SKU/Barcode/Title gets mapped manually.
                'matchedProduct' => [
                    'source_product_id' => $productData['source_product_id'],
                    'container_id' => $productData['container_id'],
                    'main_image' => $productData['main_image'],
                    'title' => $productData['title'],
                    'barcode' => $productData['barcode'],
                    'sku' => $editedProductData[0]['sku'] ?? $productData['sku'],
                    'shop_id' => $productData['shop_id']
                ],
                'matched_updated_at' => date('c'),
                'matched_at' => date('c')
            ];
            $amazonCollection->updateOne(['_id' => $listingData['_id']], ['$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1, 'manual_unlinked' => 1, 'manual_unlink_time' => 1], '$set' => $matchedData]);
            $ListingHelper = $objectManager->get(\App\Amazon\Components\Listings\ListingHelper::class);
            $ListingHelper->reactivateListing($userId, $listingData, $source_shop_id, $target_shop_id);
            // initiate inventory_sync after linking
            $reportHelper = $objectManager->get(\App\Amazon\Components\Report\Helper::class);
            $reportHelper->inventorySyncAfterLinking([$productData['source_product_id']], $params);
            return ['success' => true, 'message' => 'Amazon product has been successfully linked with selected Shopify product.'];
        }

        return ['success' => false, 'message' => 'This amazon product is already matched with shopify product.'];
    }

    /*
    * Parent Action: ProductController-> manualUnmapAction
    * Functionality: Edit a Product from Grid and unmap any variant / simple product
    */
    public function manualUnmap($params)
    {
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        $logFile = "amazon/manualUnmap/$userId/" . date('d-m-Y') . '.log';

        $source_shop_id = false;
        $target_shop_id = false;
        if (isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
        }

        if (isset($params['target']['shopId'])) {
            $target_shop_id = $params['target']['shopId'];
        }
        if (isset($params['source']['marketplace'])) {
            $sourceMarketplace = $params['source']['marketplace'];
        }


        if (!$source_shop_id || !$target_shop_id) {
            return ['success' => false, 'message' => 'Required all params.'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $sourceProductId = $params['data']['source_product_id']; //source variant id in amazon_listings.
        $mappedData = $amazonCollection->findOne([
            'user_id' => $userId,
            'shop_id' => $target_shop_id,
            'source_product_id' => $sourceProductId,
            'matched' => true,
            'matchedProduct.shop_id' => $source_shop_id
        ]);
        if ((isset($params['data']['click']) && $params['data']['click']) || (isset($params['data']['syncStatus']) && $params['data']['syncStatus']) || (isset($params['data']['createOrder']) && $params['data']['createOrder']) || (isset($params['data']['variant_delete']) && $params['data']['variant_delete']) || (isset($params['data']['removeProductWebhook']) && $params['data']['removeProductWebhook']) || (isset($params['data']['unlink_for_csv']) && $params['data']['unlink_for_csv'])) {
            if ($mappedData) {
                $containerId = $mappedData['source_container_id'];
                $asinData = [
                    [
                        'user_id' => $userId,
                        'target_marketplace' => 'amazon',
                        'shop_id' => (string)$target_shop_id,
                        'container_id' => $containerId,
                        'source_product_id' => $sourceProductId,
                        'source_shop_id' => $source_shop_id,
                        'unset' => [
                            'asin' => 1,
                            'sku' => 1,
                            'matchedwith' => 1,
                            'status' => 1,
                            'target_listing_id' => 1,
                            'fulfillment_type' => 1,
                            'shopify_sku' => 1,
                            'matched_at' => 1,
                            'amazonStatus'=>1,
                        ]
                    ]
                ];

                $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $helper->saveProduct($asinData, $userId, $params);
                $this->di->getLog()->logContent('asinData removal from product_container: ' . json_encode($asinData), 'info', $logFile);

                $record = [
                    'unmap_record' => [
                        'source' => $params['data'],
                        'unmap_time' => date('c')
                    ]
                ];
                $message = 'Product Unmapped: Your changes to this product will be automatically synchronized.';
                $unsetValues = [
                    'source_product_id' => 1,
                    'source_variant_id' => 1,
                    'source_container_id' => 1,
                    'matched' => 1,
                    'manual_mapped' => 1,
                    'matchedProduct' => 1,
                    'matchedwith' => 1,
                    'closeMatchedProduct' => 1,
                    'matched_updated_at' => 1,
                    'matched_at' => 1
                ];

                //revert from amazon_listing table -> sets as unmatched product
                $params['data']['matchedwith'] = $mappedData['matchedwith'] ?? '';
                $amazonCollection->updateOne(['_id' => $mappedData['_id']], ['$set' => $record, '$unset' => $unsetValues]);
                $this->di->getLog()->logContent('amazon_listing record: ' . json_encode($record), 'info', $logFile);
                if ((isset($params['data']['variant_delete']) && $params['data']['variant_delete']) || (isset($params['data']['removeProductWebhook']) && $params['data']['removeProductWebhook'])) {
                    $productHookObj =  $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Hook');
                    $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                    $appTag = 'amazon_sales_channel';
                    $configData->setGroupCode('inventory');
                    $configData->setUserId($userId);
                    $configData->sourceSet($sourceMarketplace);
                    $configData->setTarget('amazon');
                    $configData->setAppTag($appTag);
                    $configData->setSourceShopId($source_shop_id);
                    $configData->setTargetShopId(null);
                    $config = $configData->getConfig('inactivate_product');
                    $decodedConfig = json_decode(json_encode($config), true);
                    if (!empty($decodedConfig)) {
                        foreach ($decodedConfig as $data) {
                            if ($data['target_shop_id'] == $target_shop_id && $data['value']) {
                                $productListing[0] = $mappedData;
                                $productHookObj->initatetoInactive($productListing, $data['target_shop_id']);
                            }
                        }
                    }
                }
                return ['success' => true, 'message' => $message];
            }

            return ['success' => true, 'message' => 'No mapping found for selected product.'];
        }
        return ['success' => false, 'message' => 'Not authorized to unmap '];
    }

    public function refreshError($data)
    {
        if (isset($data['source_product_ids'])) {
            $userId = $this->di->getUser()->id;
            $homeShopId = $this->di->getRequester()->getTargetId();
            $sourceShopId = $this->di->getRequester()->getSourceId();
            $variantList = $data['source_product_ids'];
            $specifics['shop_id'] = $homeShopId;
            $specifics['source_shop_id'] = $sourceShopId;
            $specifics['user_id'] = $userId;

            $queued = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')->init($data)->ErrorRefresh($variantList, $specifics);
            if ($queued) {
                return ['success' => true, 'message' => 'Product list is large so process is in queue, you can check progress in activities'];
            }
            return ['success' => true, 'message' => 'Product Error Refreshed'];
        }
        return ['success' => false, 'message' => 'No Product Selected'];
    }

    /**
     * SQS consumer for chunked error refresh. Receives a chunk of ≤60 source_product_ids
     * (already expanded with children) and processes them via ErrorRefresh.
     */
    public function processErrorRefreshChunk(array $messageArray): void
    {
        $data = $messageArray['data'] ?? $messageArray;
        $feedId = $data['feed_id'] ?? null;
        $progressPerChunk = $data['progress_per_chunk'] ?? null;

        try {
            $product = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product');
            $feed = [
                'shop_id' => $data['shop_id'],
                'source_shop_id' => $data['source_shop_id'],
                'user_id' => $data['user_id'],
                'skip_expansion' => true,
            ];
            $product->init($data)->ErrorRefresh($data['source_product_ids'], $feed);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(
                'processErrorRefreshChunk failed: ' . $e->getMessage(),
                'info',
                'amazon/errorRefresh/' . ($data['user_id'] ?? 'unknown') . '/' . date('Y-m-d') . '.log'
            );
        }

        // Always update progress (even on failure) so the task eventually completes
        if ($feedId && $progressPerChunk) {
            $queuedTasks = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
            $result = $queuedTasks->updateFeedProgress(
                $feedId,
                $progressPerChunk,
                'Error Refresh in progress',
                true  // additive mode
            );

            // If progress hit 100, this was the last chunk — send notification
            if ($result === 100) {
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification(
                        $data['shop_id'],
                        [
                            'marketplace' => 'amazon',
                            'message' => 'Error Refresh completed',
                            'severity' => 'success',
                            'process_code' => 'amazon_error_refresh',
                            'additional_data' => [
                                'process_label' => 'Error Refresh',
                            ],
                        ]
                    );
            }
        }
    }

    public function markErrorResolved($data)
    {
        if (isset($data['source_product_ids']) && isset($data['target']['shopId']) && isset($data['source']['shopId'])) {
            $userId = $this->di->getUser()->id;
            $homeShopId = $data['target']['shopId'];
            $sourceShopId = $data['source']['shopId'];
            $variantList = $data['source_product_ids'];
            $saveData = [];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
            $products = $collection->aggregate([
                ['$match' => ['user_id' => $userId, 'shop_id' => $homeShopId, 'error' => ['$exists' => true], 'target_marketplace' => 'amazon', 'source_product_id' => ['$in' => $variantList]]],
                ['$project' => ['source_product_id' => 1, 'container_id' => 1, 'error' => 1]]
            ], ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
            if (!empty($products)) {
                foreach ($products as $product) {
                    $saveData[] = [
                        'user_id' => $userId,
                        'shop_id' => $homeShopId,
                        'target_marketplace' => 'amazon',
                        'container_id' => $product['container_id'],
                        'source_product_id' => $product['source_product_id'],
                        'source_shop_id' => $sourceShopId,
                        'unset' => ['error' => 1]
                    ];
                }
                $additionalData['source'] = [
                    'marketplace' => $data['source']['marketplace'],
                    'shopId' => (string)$data['source']['shopId']
                ];
                $additionalData['target'] = [
                    'marketplace' => $data['target']['marketplace'],
                    'shopId' => (string)$data['target']['shopId']
                ];
                $res = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($saveData, $userId, $additionalData);
                return ['success' => true, 'message' => 'Product Error Resolved'];
            } else {
                return ['success' => false, 'message' => 'Product Found with No Error'];
            }
        } else {
            return ['success' => false, 'message' => 'No Product Selected'];
        }
    }


    public function approveAllMap($products)
    {
        try {
            if (isset($products['data']['products']) && count($products['data']['products']) > 0) {
                foreach ($products['data']['products'] as $value) {
                    $data = $products;
                    $data['data'] = $value;
                    $map = $this->manualMap($data);
                }

                return ['success' => true, 'message' => 'All Products on page matched successfully'];
            }
            return ['success' => true, 'message' => 'No Products Found.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function declineCloseMatch($params)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            if (isset($params['data']['user_id'])) {
                $userId = $params['data']['user_id'];
            } else {
                $userId = $this->di->getUser()->id;
            }

            if (isset($params['target']['shopId'])) {
                $target_shop_id = $params['target']['shopId'];
            } else {
                return ['success' => false, 'message' => 'Shop Id is missing.'];
            }

            $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $listingData = $amazonCollection->updateOne(
                ['_id' => new ObjectId($params['data']['_id']), 'user_id' => $userId, 'shop_id' => $target_shop_id],
                ['$unset' => ['matchedwith' => 1, 'closeMatchedProduct' => 1]]
            );
            return ['success' => true, 'message' => 'Product declined for close match.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function manualCloseMatch($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonCollection = $mongo->getCollection(Helper::AMAZON_LISTING);
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        if (isset($params['target']['shopId']) && isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
            $target_shop_id = $params['target']['shopId'];
        } else {
            return ['success' => false, 'message' => 'Shop Id is missing.'];
        }

        $data = [
            "source_product_id" => $params['data']['source_product_id'],
            'user_id' => $userId,
            'shop_id' => $source_shop_id
        ];
        $productDataTitle = $productCollection->findOne(
            $data,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        $this->di->getLog()->logContent('data = ' . print_r($data, true), 'info', 'Productmatch.log');
        if ($productDataTitle) {
            $matchedProduct = [
                'source_product_id' => $productDataTitle['source_product_id'],
                'container_id' => $productDataTitle['container_id'],
                'main_image' => $productDataTitle['main_image'],
                'title' => $productDataTitle['title'],
                'barcode' => $productDataTitle['barcode'],
                'sku' => $productDataTitle['sku'],
                'shop_id' => $productDataTitle['shop_id']
            ];
            $closeMatch = $amazonCollection->updateOne(
                ['_id' => new ObjectId($params['data']['_id']), 'shop_id' => $target_shop_id],
                ['$set' => ['matchedwith' => 'title', 'closeMatchedProduct' => $matchedProduct]]
            );
            return ['success' => true, 'message' => 'Action reverted back successfully'];
        }
        return ['success' => false, 'message' => 'No product found to close match'];
    }

    public function getMatchStatusCount($params)
    {
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        if (isset($params['target']['shopId']) && isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
            $target_shop_id = $params['target']['shopId'];
        } else {
            return ['success' => false, 'message' => 'Shop Id is missing.'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
        try {
            if (isset($params['fulfillment-channel']) && $params['fulfillment-channel'] != 'all') {
                if ($params['fulfillment-channel'] != 'DEFAULT' && $params['fulfillment-channel'] != 'FBM') {
                    $matchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'matched' => true, 'status' => ['$ne' => 'Deleted'], 'fulfillment-channel' => ['$ne' => 'DEFAULT']]);
                    $closeMatchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'matched' => null, 'status' => ['$ne' => 'Deleted'], 'fulfillment-channel' => ['$ne' => 'DEFAULT'], 'closeMatchedProduct' => ['$exists' => true]]);
                    $notLinkedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'matched' => null, 'fulfillment-channel' => ['$ne' => 'DEFAULT']]);
                    $deletedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => 'Deleted', 'fulfillment-channel' => ['$ne' => 'DEFAULT']]);
                } else {
                    $matchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'matched' => true, 'status' => ['$ne' => 'Deleted'], 'fulfillment-channel' => 'DEFAULT']);
                    $closeMatchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'matched' => null, 'status' => ['$ne' => 'Deleted'], 'fulfillment-channel' => 'DEFAULT', 'closeMatchedProduct' => ['$exists' => true]]);
                    $notLinkedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'matched' => null, 'fulfillment-channel' => 'DEFAULT']);
                    $deletedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => 'Deleted', 'fulfillment-channel' => 'DEFAULT']);
                }
            } else {
                $matchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'matched' => true, 'status' => ['$ne' => 'Deleted']]);
                $closeMatchedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'closeMatchedProduct' => ['$exists' => true], 'matched' => ['$exists' => false]]);
                $notLinkedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => ['$ne' => 'Deleted'], 'matched' => ['$exists' => false]]);
                $deletedProductCount = $collection->count(['user_id' => $userId, 'shop_id' => $target_shop_id, 'status' => 'Deleted']);
            }

            $response = ['linked' => $matchedProductCount, 'not_linked' => $notLinkedProductCount, 'close_matched' => $closeMatchedProductCount, 'deleted' => $deletedProductCount];
            if (isset($params['getAmazonStatus']) && ($params['getAmazonStatus'] == true || $params['getAmazonStatus'] == 'true')) {
                $amazonStatus = $this->getAmazonStatusBuyable($params);
                $response['amazon_status_count'] = $amazonStatus ?? [];
            }
            return ['success' => true, 'data' => $response, 'message' => 'amazon listing data matched product'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        // $aggregation1 = $aggregation2 = $aggregation3 = [];

        // // to get no of matched products

        // $aggregation1[] = ['$match' => ['user_id' => $userId, 'shop_id' => $target_shop_id ?? '', 'matched' => true, 'matchedProduct.shop_id' => $source_shop_id]];

        // $aggregation1[] = ['$count' => "linked"];

        // // to get no of unmatched products
        // $aggregation2[] = ['$match' => [
        //     'user_id' => $userId, 'shop_id' => $target_shop_id ?? '', 'matchedwith' => ['$ne' => "title"],
        //     '$or' => [['matched' => ['$exists' => false]], ['matched' => false]]
        // ]];
        // $aggregation2[] = ['$count' => "not_linked"];

        // // to get no of close matched products
        // $aggregation3[] = ['$match' => [
        //     'user_id' => $userId, 'shop_id' => $target_shop_id ?? '',

        //     '$and' => [['closeMatchedProduct' => ['$exists' => true]], ['closeMatchedProduct.shop_id' => $source_shop_id]]
        // ]];
        // $aggregation3[] = ['$count' => "close_matched"];

        // $response = $collection->aggregate(
        //     [
        //         ['$facet' => [
        //             'linked' => $aggregation1,
        //             'not_linked' => $aggregation2,
        //             'close_matched' => $aggregation3
        //         ]],
        //         ['$project' => [
        //             'linked' => ['$ifNull' => [['$arrayElemAt' => ['$linked.linked', 0]], 0]],
        //             'not_linked' => ['$ifNull' => [['$arrayElemAt' => ['$not_linked.not_linked', 0]], 0]],
        //             'close_matched' => ['$ifNull' => [['$arrayElemAt' => ['$close_matched.close_matched', 0]], 0]]
        //         ]]
        //     ]
        // )->toArray();

        // return ['success' => true, 'data' => $response[0], 'message' => 'amazon listing data matched product'];
    }

    public function getAmazonStatusBuyable($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $userId = $this->di->getUser()->id;
        $target_shop_id = $params['target']['shopId'];
        // Base query
        $query = [
            'user_id' => $userId,
            'shop_id' => $target_shop_id
        ];
        // :white_tick: Apply filter if amazonStatus is provided in params
        if (!empty($params['filter']['amazonStatus'])) {
            // Convert filter values to an array of strings
            $statusFilter = array_values($params['filter']['amazonStatus']);
            $query['amazonStatus'] = ['$in' => $statusFilter];
        }
        // // to get no of unmatched products
        // $aggregation2[] = ['$match' => [
        //     'user_id' => $userId, 'shop_id' => $target_shop_id ?? '', 'matchedwith' => ['$ne' => "title"],
        //     '$or' => [['matched' => ['$exists' => false]], ['matched' => false]]
        // ]];
        // $aggregation2[] = ['$count' => "not_linked"];
        // Fetch only amazonStatus field
        $cursor = $collection->find(
            $query,
            ['projection' => ['amazonStatus' => 1]]
        );
        // // to get no of close matched products
        // $aggregation3[] = ['$match' => [
        //     'user_id' => $userId, 'shop_id' => $target_shop_id ?? '',
        $buyableCount = 0;
        $discoverableCount = 0;
        //     '$and' => [['closeMatchedProduct' => ['$exists' => true]], ['closeMatchedProduct.shop_id' => $source_shop_id]]
        // ]];
        // $aggregation3[] = ['$count' => "close_matched"];
        // $response = $collection->aggregate(
        //     [
        //         ['$facet' => [
        //             'linked' => $aggregation1,
        //             'not_linked' => $aggregation2,
        //             'close_matched' => $aggregation3
        //         ]],
        //         ['$project' => [
        //             'linked' => ['$ifNull' => [['$arrayElemAt' => ['$linked.linked', 0]], 0]],
        //             'not_linked' => ['$ifNull' => [['$arrayElemAt' => ['$not_linked.not_linked', 0]], 0]],
        //             'close_matched' => ['$ifNull' => [['$arrayElemAt' => ['$close_matched.close_matched', 0]], 0]]
        //         ]]
        //     ]
        // )->toArray();
        foreach ($cursor as $doc) {
            if (!empty($doc['amazonStatus'])) {
                $statusArray = (array) $doc['amazonStatus'];
                if (in_array("BUYABLE", $statusArray)) {
                    $buyableCount++;
                }
                if (in_array("DISCOVERABLE", $statusArray)) {
                    $discoverableCount++;
                }
            }
        }
        return [
            'buyable' => $buyableCount,
            'discoverable' => $discoverableCount
        ];
        // return ['success' => true, 'data' => $response[0], 'message' => 'amazon listing data matched product'];
    }
    
    public function bulkSwitch($data)
    {
        try {
            // Extract data from payload
            $sourceProductIds = $data['source_product_ids'];
            $targetShopId = $data['target']['shopId'];
            $targetMarketplace = $data['target']['marketplace'];
            $attributes = $data['attributes'];
            $userId = $this->di->getUser()->id;

            // Get MongoDB instance and collection
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

            // Build query filter to find the target documents
            $filterQuery = [
                'user_id' => $userId,
                'source_product_id' => ['$in' => $sourceProductIds],
                'shop_id' => (string)$targetShopId,
                'target_marketplace' => (string)$targetMarketplace
            ];

            // Find all matching documents
            $existingDocuments = $productContainerCollection->find($filterQuery, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
    
            if (empty($existingDocuments)) {
                return [
                    'success' => false,
                    'message' => 'No product found matching the provided criteria',
                    'data' => []
                ];
            }
            
            // Build attribute mapping (supports multiple fields per attribute)
            $attributeMapping = [
                'title' => ['title'],
                'Description' => ['description'],
                'Price' => ['price', 'price_rules'],
                'Barcode' => ['barcode'],
                'Variant SKU' => ['sku'],
                'Images' => ['main_image','addtional_images'],
                'attribute_mapping' => ['category_settings','edited_variant_jsonFeed'],
            ];

            // Build data array for saveProduct
            $saveProductData = [];
            $allUnsetAttributes = [];

            foreach ($existingDocuments as $existingDocument) {
                // Build unset array for specified attributes
                $unsetData = [];
                foreach ($attributes as $attribute) {
                    if (isset($attributeMapping[$attribute])) {
                        $fieldNames = $attributeMapping[$attribute];
                        foreach ($fieldNames as $fieldName) {
                            if (isset($existingDocument[$fieldName])) {
                                $unsetData[$fieldName] = 1;
                            }
                        }
                    } else {
                        $fieldName = strtolower($attribute);
                        if (isset($existingDocument[$fieldName])) {
                            $unsetData[$fieldName] = 1;
                        }
                    }
                }

                if (!empty($unsetData)) {
                    $saveProductData[] = [
                        'source_product_id' => $existingDocument['source_product_id'],
                        'container_id' => $existingDocument['container_id'] ?? $existingDocument['source_product_id'],
                        'target_marketplace' => (string)$targetMarketplace,
                        'shop_id' => (string)$targetShopId,
                        'source_shop_id' => $existingDocument['source_shop_id'] ?? '',
                        'unset' => $unsetData
                    ];
                    $allUnsetAttributes = array_merge($allUnsetAttributes, array_keys($unsetData));
                }
            }

            if (empty($saveProductData)) {
                return [
                    'success' => false,
                    'message' => 'None of the specified attributes exist in the target documents',
                    'data' => ['attributes_checked' => $attributes]
                ];
            }

            // Use saveProduct function from Edit model
            $editModel = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $firstDocument = $existingDocuments[0];
            $additionalData = [
                'source' => [
                    'marketplace' => $data['source']['marketplace'] ?? $firstDocument['source_marketplace'] ?? '',
                    'shopId' => (string)($data['source']['shopId'] ?? $firstDocument['source_shop_id'] ?? '')
                ],
                'target' => [
                    'marketplace' => $targetMarketplace,
                    'shopId' => (string)$targetShopId
                ],
                'dont_validate' => true
            ];

            $saveResult = $editModel->saveProduct($saveProductData, $userId, $additionalData);

            if (isset($saveResult['success']) && $saveResult['success']) {
                return [
                    'success' => true,
                    'message' => 'Bulk switch operation is successfully completed',
                    'data' => [
                        'source_product_ids' => $sourceProductIds,
                        'target_shop_id' => $targetShopId,
                        'target_marketplace' => $targetMarketplace,
                        'unset_attributes' => array_unique($allUnsetAttributes),
                        'modified_count' => count($saveProductData)
                    ]
                ];
            } else {
                return [
                    'success' => $saveResult['success'] ?? false,
                    'message' => $saveResult['message'] ?? 'No changes were made to the documents',
                    'data' => $saveResult['data'] ?? [
                        'attempted_unset' => array_unique($allUnsetAttributes)
                    ]
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error occurred during bulk switch: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    public function getAmazonStatus($params)
    {
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        if (isset($params['target']['shopId']) && isset($params['source']['shopId'])) {
            $source_shop_id = $params['source']['shopId'];
            $target_shop_id = $params['target']['shopId'];
        } else {
            return ['success' => false, 'message' => 'Shop Id is missing.'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $filter = ['user_id' => $userId, 'shop_id' => $target_shop_id ?? ''];
        $response = $collection->distinct("status", $filter);
        return ['success' => true, 'data' => $response, 'message' => 'Product Status on Amazon'];
    }


    public function getUploadFileUrl($file_name)
    {


        $file_path = BP . DS . '/var/file/images/' . $file_name;

        // $this->di->getLog()->logContent('getting url for file ' . print_r($file_path, true), 'info', 'image_url.log');
        $s3Client = new S3Client(include BP . '/app/etc/aws.php');

        $result = $s3Client->putObject([
            'Bucket' => $this->di->getConfig()->get('s3_bucket'),
            'Key' => basename($file_path),
            'Body'   => fopen($file_path, 'r'),
            'ACL'    => 'public-read', // make file 'public'
        ]);
        $imageUrl = $result->get('ObjectURL');
        // echo "Image uploaded successfully. Image path is: " . $result->get('ObjectURL');
        return $imageUrl;
    }


    public function uploadImageInS3Bucket($file_name)
    {

        $response = $this->getUploadFileUrl($file_name);

        if ($response) {
            $data = $file_name;
            $dir = BP . DS . '/var/file/images';
            $dirHandle = opendir($dir);
            while ($file = readdir($dirHandle)) {
                // print_r($file);
                if ($file == $data) {
                    unlink($dir . '/' . $file);
                }
            }

            closedir($dirHandle);
        }

        return $response;
    }

    public function checkBeforeS3ImageUpload($request)
    {
        $imageUploadConfig = $this->di->getConfig()->image_upload_config?->toArray();
        $isMaxSizeEnabled = $imageUploadConfig['enable_max_size'] ?? false;

        if (!$isMaxSizeEnabled) {
            return ['success' => true];
        }

        // Get max size in bits
        $maxSizeBits = $imageUploadConfig['max_size'] ?? PHP_INT_MAX;
        // Convert max size to MB for the message
        $maxSizeMB = $maxSizeBits / (1024 * 1024);

        $requestResponse = ['success' => true];

        if ($request?->hasFiles()) {
            foreach ($request->getUploadedFiles() as $file) {
                if ($file->getSize() > $maxSizeBits) {
                    $requestResponse['success'] = false;
                    $requestResponse['exceeded_files'][] = $file->getName();
                }
            }
        }
        if (!$requestResponse['success']) {
            $requestResponse['message'] = sprintf("Image size should be less than %.2f MB.", $maxSizeMB);
        }

        return $requestResponse;
    }

    public function saveChecklist($data)
    {
        $sourceShopId = isset($sourceShopId) ? $this->di->getRequester()->getSourceId() : $data['source']['shopId'];

        $targetShopId = isset($targetShopId) ? $this->di->getRequester()->getTargetId() : $data['target']['shopId'];

        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = isset($source) ? $this->di->getRequester()->getSourceName() : $data['source']['marketplace'];
        $target = isset($target) ? $this->di->getRequester()->getTargetName() : $data['target']['marketplace'];


        if (!($sourceShopId) || !($app_tag) || !($source)) {
            return ['success' => false, 'message' => 'Source shop or Source or app tag not found'];
        }
        $saveData['source'] = $source;
        $saveData['app_tag'] = $app_tag;
        $saveData['source_shop_id'] = $sourceShopId;
        $saveData['group_code'] = 'onboarding';
        $saveData['target_shop_id'] = $targetShopId;
        $saveData['target'] = $target;

        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setUserId();
        $saveData['key'] = Helper::ONBOARDING_CHECKLIST;
        $saveData['value'] = $data['data']['onboarding_checklist'];
        $res = $configObj->setConfig([$saveData]);
        return ['success' => true, 'data' => $res];
    }

    //  Enable Notification to Seller
    public function notifyAmazonSellers()
    {
        $userId = $this->di->getUser()->id;

        $notification = '<p>Alert! Kindly Renew SP-API Authorization Token in your Amazon Seller Account. <a href="https://bit.ly/3AfiOnK" target="_blank">Click to view the Guide</a></p>';
        $notifySeller = false;

        $updatedAt = "";
        $targetShopId = $this->di->getRequester()->getTargetId();

        $currentDate = date("Y-m-d h:i:s");

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $userAmazonShop = $collection->findOne(["user_id" => $userId], ["projection" => ["shops" => 1, "_id" => 0], "typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (isset($userAmazonShop['shops']) && !empty($userAmazonShop['shops'])) {
            foreach ($userAmazonShop['shops'] as $shop) {
                if (isset($shop['marketplace']) && $shop['marketplace'] == "amazon" && $shop['_id'] == $targetShopId) {
                    $utcdatetime = $shop['updated_at'];
                    if (is_object($utcdatetime) && !empty($utcdatetime)) {
                        $datetime = $utcdatetime->toDateTime();
                        $updatedAt = $datetime->format('Y-m-d h:i:s');
                        if ((strtotime($currentDate) - strtotime((string) $updatedAt)) / 86400 >= 365)
                            $notifySeller = true;

                        break;
                    }
                }
            }
        }

        $responseData['notify_seller'] = $notifySeller;
        $responseData['notification'] = $notification;
        $responseData['applicable_date'] = $currentDate;
        $responseData['updated_at'] = $updatedAt;

        return ['success' => true, 'data' => $responseData];
    }


    public function DeleteProductFromAmazon($data)
    {
        try {
            if (isset($data['source']['shopId'], $data['target']['shopId'], $data['deleteData']['sku'])) {
                $sourceShopId = $data['source']['shopId'];
                $targetShopId = $data['target']['shopId'];
                $sku = $data['deleteData']['sku'];
                $dataFoundOnAmazon = $data['dataFoundOnAmazon'];
            } else {
                return ['success' => false, 'message' => 'Required param(s) missing : sourceShopId, targetShopId, sku'];
            }

            $userId = $data['deleteData']['user_id'] ?? $this->di->getUser()->id;
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $userDetails->getShop($targetShopId, $userId);
            $specifics = [
                'shop_id' => $targetShop['remote_shop_id'],
                'sku' => $sku
            ];
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $isdeleted = false;
            if (is_bool($dataFoundOnAmazon)) {
                if ($dataFoundOnAmazon) {
                    $response = $commonHelper->sendRequestToAmazon('listing-delete', $specifics, 'GET');
                    if (isset($response['response']['status']) && $response['response']['status'] == 'ACCEPTED') {
                        $isdeleted = true;
                    }
                } else {
                    $isdeleted = true;
                }
            }

            if ($isdeleted) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                $query = ['user_id' => $userId, 'shop_id' => $targetShopId,  'seller-sku' => $sku];
                $listing = $amazonCollection->findOne($query, $options);
                if (isset($listing['matched'], $listing['source_product_id'], $listing['source_container_id'])) {
                    /** unlink product before deleting*/
                    $asinData = [
                        [
                            'user_id' => $userId,
                            'target_marketplace' => 'amazon',
                            'shop_id' => (string)$targetShopId,
                            'container_id' => $listing['source_container_id'],
                            'source_product_id' => $listing['source_product_id'],
                            'source_shop_id' => $sourceShopId,
                            'unset' => [
                                'asin' => 1,
                                'sku' => 1,
                                'matchedwith' => 1,
                                'status' => 1,
                                'target_listing_id' => 1,
                                'fulfillment_type' => 1
                            ]
                        ]
                    ];

                    $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    $editHelper->saveProduct($asinData, $userId);
                }
                /** delete listing from app */
                $amazonCollection->deleteOne($query);
                return ['success' => true, 'message' => 'Product successfully deleted from App.'];
            }
            return ['success' => false, 'message' => 'Product could not be deleted from Amazon.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    public function CheckDataOnAmazon($data)
    {
        try {
            if (isset($data['source']['shopId'], $data['target']['shopId'], $data['CheckData']['sku'])) {
                $sourceShopId = $data['source']['shopId'];
                $targetShopId = $data['target']['shopId'];
                $sku = $data['CheckData']['sku'];
            } else {
                return ['success' => false, 'message' => 'Required param(s) missing : sourceShopId, targetShopId, sku'];
            }

            $userId = $data['checkData']['user_id'] ?? $this->di->getUser()->id;
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $userDetails->getShop($targetShopId, $userId);
            $specifics = [
                'shop_id' => $targetShop['remote_shop_id'],
                'sku' => $sku
            ];
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $checkProductResponse = $commonHelper->sendRequestToAmazon('listing', $specifics, 'GET');
            if (isset($checkProductResponse['success'], $checkProductResponse['response']['sku']) && $checkProductResponse['success']) {
                if (($checkProductResponse['response']['sku'] == $sku) && isset($checkProductResponse['response']['summaries']) && !empty($checkProductResponse['response']['summaries'])) {
                    return ['success' => true, 'message' => 'Product exists on Amazon.'];
                }
            }

            return ['success' => false, 'message' => 'Product not found on Amazon.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getFeedDataDirect($data)
    {
        try {
            if (isset($data['source']['shopId'], $data['target']['shopId'])) {
                $data['user_id'] = (string) $this->di->getUser()->id;
                $data['limit'] = 1000;
                if (!isset($data['activePage'])) {
                    $data['activePage'] = 1;
                }

                $data['operationType'] = 'product_upload';
                $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
                $sourceModel = $this->di->getObjectManager()->get(SourceModel::class);
                $products = $productProfile->getproductsByProductIds($data, true);
                $dataToSent = json_decode(json_encode($products), true);
                $dataToSent['data']['operationType'] = 'product_upload';
                $dataToSent['data']['params'] = $data;
                if (!empty($data['return_feed'])) {
                    $dataToSent['data']['return_feed'] = $data['return_feed'];
                }

                $dataToSent['data']['params'] = $data;
                return $sourceModel->startSync($dataToSent);
            }
            return ['success' => false, 'message' => 'Required param(s) missing : sourceShopId, targetShopId, sku'];

            return ['success' => false, 'message' => 'Product not found on Amazon.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    // SKU count with respect to Status
    public function skucount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $refineCollection = $mongo->getCollectionForTable('refine_product');
        $source = $this->di->getRequester()->getSourceId();
        $target = $this->di->getRequester()->gettargetId();
        $userId = (string) $this->di->getUser()->id;
        // $totalProduct =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $source]);
        $totalProduct =  $refineCollection->countDocuments(['user_id' => $userId, 'source_shop_id' => $source, 'target_shop_id' => $target]);
        $ChildCount =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $source, 'type' => 'simple']);
        $ParentCount =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $source, 'type' => 'variation']);
        $InactiveProduct =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $target, 'status' => 'Inactive']);
        $ActiveProduct =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $target, 'status' => 'Active']);
        $IncompleteProduct =  $productCollection->countDocuments(['user_id' => $userId, 'shop_id' => $target, 'status' => 'Incomplete']);
        $NotListedChild = $ChildCount - ($InactiveProduct + $ActiveProduct + $IncompleteProduct);
        $NotListedParent  = $ParentCount - $IncompleteProduct;
        $arr[] = ['ChildCount' => $ChildCount, 'ParentCount' => $ParentCount, 'InactiveProduct' => $InactiveProduct, 'ActiveProduct' => $ActiveProduct, 'totalProduct' => $totalProduct, 'NotListedChild' => $NotListedChild, 'IncompleteProduct' => $IncompleteProduct, 'NotListedParent' => $NotListedParent];
        return ['success' => true, 'data' => $arr];
    }

    public function getProductTypeList($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $p_con = $mongo->getCollectionForTable('product_container');

        $aggregate = [];

        $aggregate[] = [
            '$searchMeta' => [
                'index' => 'status',
                'facet' => [
                    'operator' => [
                        'compound' => [
                            'must' => [
                                [
                                    'text' => [
                                        'query' => $this->di->getUser()->id,
                                        'path' => 'user_id',
                                    ]
                                ],
                                [
                                    'text' => [
                                        'query' => $this->di->getRequester()->getTargetId(),
                                        'path' => 'shop_id',
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'facets' => [
                        'categories' => [
                            'type' => 'string',
                            'path' => 'amazonProductType',
                            "numBuckets" => 500
                        ]
                    ]
                ],
                'count' => [
                    'type' => 'total'
                ]
            ]
        ];

        $res = $p_con->aggregate($aggregate)->toArray();

        return [
            'success' => true,
            'data' => $res
        ];
    }

    public function deleteS3Images($rawBody)
    {
        $response = [
            'success' => false,
            'deleted' => [],
            'failed'  => [],
            'message' => ''
        ];

        if (!isset($rawBody['img_urls']) || empty($rawBody['img_urls']) || !is_array($rawBody['img_urls'])) {
            $response['message'] = 'At least one URL is required to delete S3 images.';
            return $response;
        }

        // Load AWS config once
        $config = include BP . '/app/etc/aws.php';
        $s3Client = new \Aws\S3\S3Client($config);

        foreach ($rawBody['img_urls'] as $url) {
            try {
                // Parse S3 URL
                $parsedUrl = parse_url($url);
                $path = ltrim($parsedUrl['path'], '/');

                // Handle both virtual-hosted and path-style URLs
                $hostParts = explode('.', $parsedUrl['host']);
                if ($hostParts[0] === 's3') {
                    // Path-style: s3.region.amazonaws.com/bucket/key
                    $pathParts = explode('/', $path);
                    $bucket = array_shift($pathParts);
                    $path = implode('/', $pathParts);
                } else {
                    // Virtual-hosted: bucket.s3.region.amazonaws.com/key
                    $bucket = $hostParts[0];
                }

                // Delete object
                $result = $s3Client->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $path
                ]);

                // Confirm deletion (handles caching confusion)
                $exists = $s3Client->doesObjectExist($bucket, $path);

                if ($result['@metadata']['statusCode'] === 204 && !$exists) {
                    $response['deleted'][] = [
                        'url'    => $url,
                        'bucket' => $bucket,
                        'key'    => $path,
                        'status' => 'Deleted'
                    ];
                } else {
                    $response['failed'][] = [
                        'url'    => $url,
                        'bucket' => $bucket,
                        'key'    => $path,
                        'status' => 'Delete request sent but object still exists'
                    ];
                }
            } catch (\Aws\S3\Exception\S3Exception $e) {
                $response['failed'][] = [
                    'url'    => $url,
                    'error'  => 'S3 error: ' . $e->getMessage()
                ];
            } catch (\Throwable $e) { // catches both \Exception and \Error
                $response['failed'][] = [
                    'url'    => $url,
                    'error'  => 'Unexpected error: ' . $e->getMessage()
                ];
            }
        }

        $response['success'] = empty($response['failed']);
        $response['message'] = $response['success']
            ? 'All images deleted successfully.'
            : 'Some images could not be deleted.';

        return $response;
    }

    public function extractLabels($data)
    {

        // $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $p_con = $mongo->getCollectionForTable('product_container');
        if ($data['user_id']) {
            $user_id = $data['user_id'];
        } else {
            $user_id = $this->di->getUser()->id;
        }

        if ($data['source_marketplace']) {
            $source_shop_id = $data['source_marketplace']['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
            $source_marketplace = $data['source_marketplace']['marketplace'] ?? $this->di->getRequester()->getSourceName();
        }
        if ($data['target_marketplace']) {
            $target_shop_id = $data['target_marketplace']['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $target_marketplace = $data['target_marketplace']['marketplace'] ?? $this->di->getRequester()->getTargetName();
        }

        if ($data['source_product_id']) {
            $source_product_id = $data['source_product_id'];
        }

        $shops = $this->di->getUser()->shops;
        foreach ($shops as $shop) {
            if ($target_shop_id == $shop['_id']) {
                $remoteShopId = $shop['remote_shop_id'];
                $marketplaceId = $shop['warehouses'][0]['marketplace_id'];
                $productType = $data['product_type'];
                $dataForSchema = [
                    'remote_shop_id' => $data['remote_shop_id'] ?? $remoteShopId,
                    'shop_id' => $target_shop_id,
                    'product_type' => $productType,
                    'marketplace_id' => $data['marketplace_id'] ?? $marketplaceId,
                    'user_id' => $user_id
                ];
                $schema = $this->di->getObjectManager()->get('App\Amazon\Components\Listings\CategoryAttributes')->getProductTypeDefinitionSchema($dataForSchema);
                $schema = json_decode($schema['data']['schema'], true);
                $items = $schema['properties']['merchant_shipping_group'];

                if (isset($data['merchant_shipping_group_id'])) {
                    $marchant_shipping_group = $data['merchant_shipping_group_id'];
                    $enumValues = $items['items']['properties']['value']['enum'] ?? [];
                    $foundIndex = array_search($marchant_shipping_group, $enumValues);
                    if ($foundIndex !== false) {
                        $enumNames = $items['items']['properties']['value']['enumNames'] ?? [];
                        $result = $enumNames[$foundIndex];
                    } else {
                        $enumNames = $items['items']['properties']['value']['enumNames'] ?? [];
                        $foundIndex = array_search($marchant_shipping_group, $enumNames);
                        if ($foundIndex !== false) {
                            $enumValues = $items['items']['properties']['value']['enum'] ?? [];
                            $result = $enumValues[$foundIndex];
                        } else {
                            return ['success' => false, 'msg' => 'data Not Found'];
                        }
                    }
                }
            }
        }
        return ['success' => true, 'data' => $result];
    }
}
