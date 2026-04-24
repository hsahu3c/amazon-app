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
 * @package     Ced_Shopifynxtgen
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Parser;

use App\Shopifyhome\Components\Product\BatchImport;
use App\Shopifyhome\Components\Parser\ReadFile;

class JSONLParser extends ReadFile
{
    public const DELIMETER = '~';

    public const MAX_VARIANT_COUNT_PROCESSABLE = 100;

    public const MARKETPLACE_CODE = 'shopify';

    public const LARGE_VARIANT_COUNT_CONFIG_KEY = 'product_2048_support_handling';

    public function getItemCount()
    {
        $counts = [];

        $jsonl_formatter = new JSONLFormatter();

        $file_cursor_position = 0;
        while ($file_cursor_position < $this->getFileSize()) {
            $json_lines_data = $this->getJsonLines(['seek' => $file_cursor_position]);

            $json = $json_lines_data['data'];
            $items = json_decode($json, true);

            foreach ($items as $item) {
                if (isset($item['id'])) {
                    $id_info = $jsonl_formatter->getIdInfo($item['id']);
                    if (isset($counts[$id_info['type']])) {
                        $counts[$id_info['type']]++;
                    } else {
                        $counts[$id_info['type']] = 1;
                    }
                }
            }

            $file_cursor_position = $json_lines_data['seek'];
        }

        return [
            'success'    => true,
            'data'        => $counts
        ];
    }

    public function getItems($params)
    {
        $limit = isset($params['limit']) ? intval($params['limit']) : 250;
        $item_limit = isset($params['item_limit']) ? intval($params['item_limit']) : 10000;
        $file_cursor = 0;
        $skip_till_key = -1;

        $skipLargeVariantCount = $this->resolveSkipLargeVariantCountFromConfig();
        $skippedContainerIds = [];
        if (isset($params['next'])) {
            $validate = $this->validateNext($params['next']);
            if ($validate['status']) {
                $explode = explode(self::DELIMETER, (string) $validate['data']);
                array_walk($explode, function (&$item) {
                    $item = intval($item);
                });
                 [$limit, $file_cursor, $skip_till_key] = $explode;
            } else {
                return [
                    'success'    => false,
                    'msg'       => 'Next Cursor is Invalid'
                ];
            }
        }

        $count = 0;
        $total_items = 0;

        $jsonl_formatter = new JSONLFormatter();

        $buffer_items = [];
        $buffer_count = 0;

        $buffer_start_cursor = $file_cursor;
        $buffer_start_key = $skip_till_key;

        $flag_broken = false;
        $next_cursor_position = $file_cursor;
        $next_skip_key = -1;

        while ($file_cursor < $this->getFileSize()) {
            $json_lines_data = $this->getJsonLines(['seek' => $file_cursor]);
            $items = json_decode($json_lines_data['data'], true);

            foreach ($items as $key => $item) {
                /**
                 * if $skip_till_key is 17 then we'll skip till key 16. From 17 we'll start processing
                 */
                if ($skip_till_key > $key) {
                    continue;
                }
                $skip_till_key = -1;

                $is_parent = !isset($item['__parentId']);

                if ($is_parent) {
                    if (!empty($buffer_items)) {
                        $skipBuffer = $skipLargeVariantCount && $this->bufferExceedsMaxVariantCount($buffer_items);
                        if (!$skipBuffer) {
                            if ($count > 0 && ($count >= $limit || ($total_items + $buffer_count) > $item_limit)) {
                                $flag_broken = true;
                                $next_cursor_position = $buffer_start_cursor;
                                $next_skip_key = $buffer_start_key;
                                break 2;
                            }

                            foreach ($buffer_items as $b_item) {
                                $jsonl_formatter->setItem($b_item);
                            }
                            $count++;
                            $total_items += $buffer_count;
                        } else {
                            $this->appendSkippedContainerIdFromBuffer($jsonl_formatter, $buffer_items, $skippedContainerIds);
                        }

                        $buffer_items = [];
                        $buffer_count = 0;
                    }

                    $buffer_start_cursor = $file_cursor;
                    $buffer_start_key = $key;
                }

                $buffer_items[] = $item;
                if ($is_parent) {
                    $buffer_count++; // parent = 1 logical item
                } elseif (isset($item['id']) && strpos($item['id'], 'ProductVariant') !== false) {
                    $buffer_count++; // variant = 1 logical item
                }
            }

            if ($flag_broken) {
                break;
            }

            $file_cursor = $json_lines_data['seek'];
            $skip_till_key = -1;
        }

        if (!$flag_broken && !empty($buffer_items)) {
            $skipBuffer = $skipLargeVariantCount && $this->bufferExceedsMaxVariantCount($buffer_items);
            if (!$skipBuffer) {
                if ($count > 0 && ($count >= $limit || ($total_items + $buffer_count) > $item_limit)) {
                    $flag_broken = true;
                    $next_cursor_position = $buffer_start_cursor;
                    $next_skip_key = $buffer_start_key;
                } else {
                    foreach ($buffer_items as $b_item) {
                        $jsonl_formatter->setItem($b_item);
                    }
                    $count++;
                    $total_items += $buffer_count;

                    $next_cursor_position = $file_cursor;
                    $next_skip_key = -1;
                }
            } else {
                $this->appendSkippedContainerIdFromBuffer($jsonl_formatter, $buffer_items, $skippedContainerIds);
                $next_cursor_position = $file_cursor;
                $next_skip_key = -1;
            }
        }
        $response = [
            'success'    => true,
            'data'        => $jsonl_formatter->getFormattedData()
        ];
        if ($flag_broken) {
            $response['cursors']['next'] = urlencode(base64_encode($limit . self::DELIMETER . $next_cursor_position . self::DELIMETER . $next_skip_key));
        } else {
            if ($next_cursor_position !== 0 && $next_cursor_position !== $this->getFileSize()) {
                $response['cursors']['next'] = urlencode(base64_encode($limit . self::DELIMETER . $next_cursor_position . self::DELIMETER . '-1'));
            }
        }

        if ($skipLargeVariantCount && !empty($skippedContainerIds)) {
            $skippedContainerIds = array_values(array_unique(array_filter($skippedContainerIds)));
            $this->dispatchSkippedLargeVariantSideEffects($skippedContainerIds);
        }

        return $response;
    }

    private function bufferExceedsMaxVariantCount($bufferItems)
    {
        if ($bufferItems === []) {
            return false;
        }
        $n = 0;
        foreach ($bufferItems as $row) {
            if (isset($row['id']) && strpos((string) $row['id'], 'ProductVariant') !== false) {
                $n++;
            }
        }

        return $n > self::MAX_VARIANT_COUNT_PROCESSABLE;
    }

    private function appendSkippedContainerIdFromBuffer($formatter, $bufferItems, &$skippedContainerIds)
    {
        $parent = $bufferItems[0] ?? null;
        if (!is_array($parent) || !isset($parent['id'])) {
            return;
        }
        $info = $formatter->getIdInfo($parent['id']);
        if (!empty($info['id'])) {
            $skippedContainerIds[] = (string) $info['id'];
        }
    }

    private function dispatchSkippedLargeVariantSideEffects($containerIds)
    {
        if ($containerIds === []) {
            return;
        }
        $userId = $this->di->getUser()->id;
        if (empty($userId)) {
            return;
        }
        $shopData = $this->getShopByMarketplace();
        if (empty($shopData)) {
            return;
        }
        $shopId = $shopData['_id'];
        if (isset($shopData['reauth_required']) && $shopData['reauth_required']) {
            $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
            return;
        }

        foreach (array_chunk($containerIds, BatchImport::BATCH_IMPORT_UNASSIGN_PRODUCT_IDS_CHUNK) as $containerIdsBatch) {
            $handlerData = [
                'type'       => 'full_class',
                'class_name' => BatchImport::class,
                'method'     => 'unassignProductInBulk',
                'user_id'    => $userId,
                'data'       => [
                    'container_ids' => $containerIdsBatch,
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                ],
                'shop_id'    => $shopId,
                'marketplace'=> 'shopify',
                'queue_name' => BatchImport::UNASSIGN_PRODUCT_IN_BULK_QUEUE
            ];
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($handlerData);
        }
    }

    private function resolveSkipLargeVariantCountFromConfig()
    {
        if ($this->di->getConfig()->get(self::LARGE_VARIANT_COUNT_CONFIG_KEY) === null) {
            return false;
        }

        $userId = $this->di->getUser()->id;
        if (empty($userId)) {
            return false;
        }

        $batchImport = $this->di->getObjectManager()->get(BatchImport::class);
        $cacheKey2048 = BatchImport::CACHE_KEY_2048_VARIANT_SUPPORT_USERS;
        $enabledUserIds = $this->di->getCache()->get($cacheKey2048);
        if ($enabledUserIds === null) {
            $enabledUserIds = $batchImport->fetchAndSet2048VariantSupportUsers();
        }

        $userHas2048VariantSupport = !empty($enabledUserIds) && is_array($enabledUserIds)
            && in_array($userId, $enabledUserIds, false);
        // For now skipping variants count greater than 100 for all users
        // $userHas2048VariantSupport = false;
        return !$userHas2048VariantSupport;
    }

    private function getShopByMarketplace()
    {
        $shops = $this->di->getUser()->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == self::MARKETPLACE_CODE) {
                    return $shop;
                }
            }
        }

        return null;
    }

    public function validateNext($next)
    {
        $next = urldecode((string) $next);
        $next = base64_decode($next, true);

        if($next) {
            if(substr_count($next, self::DELIMETER) === 2) {
                return [
                    'status'=> true,
                    'data'	=> $next
                ];
            }
        }

        return ['status' => false];
    }
}