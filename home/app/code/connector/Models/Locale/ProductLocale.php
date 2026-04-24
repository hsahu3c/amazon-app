<?php

namespace App\Connector\Models\Locale;

use App\Connector\Models\Product\Marketplace;
use App\Connector\Models\ProductContainer;

class ProductLocale extends ProductContainer implements LocaleManager
{
    protected $table = 'product_container';

    private function getSupportedLocale()
    {
        return $this->di->getConfig()->get('supported_locales')->toArray() ?? self::SUPPORTED_LOCALES;
    }

    private function prepareQuery($products, $userId = null): array
    {
        $userId ??= $this->di->getUser()->id;
        $data = [];
        foreach ($products as $product) {
            $setValue = [];
            if (
                !isset($product['shop_id'], $product['container_id'], $product['source_product_id'], $product['locale'])
            ) {
                $data['notUpdatedProducts'][] = array_merge($product, [
                    'success' => false,
                    'message' => $this->di->getLocale()->_('missing', ['msg' => $this->di->getLocale()->_('required_data')])
                ]);
            } else {
                foreach ($product['locale'] as $key => $value) {
                    if (in_array($key, $this->getSupportedLocale())) {
                        $setValue["locale.{$key}"] = $value;
                    }
                }

                if (!empty($setValue)) {
                    $data['query'][] = [
                        'updateOne' => [
                            [
                                'user_id' => $userId,
                                'shop_id' => $product['shop_id'],
                                'container_id' => $product['container_id'],
                                'source_product_id' => $product['source_product_id']
                            ],
                            [
                                '$set' => $setValue
                            ]
                        ]
                    ];
                } else {
                    $data['notUpdatedProducts'][] = array_merge($product, [
                        'success' => false,
                        'message' => $this->di->getLocale()->_('not_supported_locale')
                    ]);
                }
            }
        }

        return $data;
    }

    private function validator(array $data): array
    {
        $response = [
            'success' =>true
        ];
        if (empty($data['products'])) {
            $response = [
                'success' => false,
                'message' => $this->di->getLocale()->_('missing', ['msg' => $this->di->getLocale()->_('required_data')])
            ];
        } elseif (!empty($data['products']) && count($data['products']) > self::DEFAULT_LIMIT) {
            $response = [
                'success' => false,
                'message' => $this->di->getLocale()->_('process_limit', ['msg' => '>' . self::DEFAULT_LIMIT])
            ];
        } elseif (!empty($data['products'])) {
            $valid = true;
            foreach ($data['products'] as $value) {
                if (!is_array($value['locale']) || empty($value['locale'])) {
                    $valid = false;
                }
            }

            if (!$valid) {
                $response = [
                    'success' => false,
                    'message' => $this->di->getLocale()->_('invalid_data')
                ];
            }
        }

        return $response;
    }

    public function saveLocale($data)
    {
        $validation = $this->validator($data);
        if (empty($validation['success'])) {
            return $validation;
        }

        $result = [];
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $prepareQuery = $this->prepareQuery($data['products'], $userId);

        if (!empty($prepareQuery['query'])) {
            $result = $this->executeBulkQuery($this->table, $prepareQuery['query']);
        }

        if (!empty($result['bulkObj'])) {
            $this->updateMarketplaceData($prepareQuery['query']);
            $result['message'] = $this->di->getLocale()->_('update_success', [ 'msg' => $result['bulkObj']->getModifiedCount(), 'action' => 'Products']);
            $result['modified_count'] = $result['bulkObj']->getModifiedCount();
        }

        if (empty($result) && !empty($prepareQuery['notUpdatedProducts'])) {
            $result = [
                'success' => false,
                'notUpdatedProducts' => $prepareQuery['notUpdatedProducts'],
                'message' => $this->di->getLocale()->_('Somthing went wrong!')
            ];
        }

        if (!empty($result) && !empty($prepareQuery['notUpdatedProducts'])) {
            $result['notUpdatedProducts'] = $prepareQuery['notUpdatedProducts'];
            $result['message'] = $result['message'] . $this->di->getLocale()->_('have_issue', [ 'msg' => count($prepareQuery['notUpdatedProducts']), 'action' => 'Products']);
            $result['not_modified_count'] = count($prepareQuery['notUpdatedProducts']);
        }


        return $result;
    }

    private function updateMarketplaceData($data)
    {
        $filter = [];
        $marketplaceData = [];
        foreach ($data as $item) {
            if (isset($item['updateOne'])) {
                $filterData = $item['updateOne'][0];
                $filter['source_product_id'][] = $filterData['source_product_id'];
                $filter['shop_id'] = $filterData['shop_id'];
                $filter['user_id'] = $filterData['user_id'];
            }
        }

        $query = [
            [
                '$match' => [
                    'user_id' => $filter['user_id'],
                    'shop_id' => $filter['shop_id'],
                    'source_product_id' => [ '$in' => $filter['source_product_id']]
                ]
            ]
        ];

        $productData = $this->executeAggregateQuery($this->table, $query);
        if (!empty($productData)) {
            foreach ($productData as $product) {
                $marketplaceData[] = $this->prepareMarketplace($product);
            }
        }

        return $this->di->getObjectManager()->get(Marketplace::class)->marketplaceSaveAndUpdate($marketplaceData);
    }
}
