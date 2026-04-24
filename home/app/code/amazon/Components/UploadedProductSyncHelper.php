<?php

namespace App\Amazon\Components;

use App\Core\Components\Base as Base;
use App\Amazon\Models\SourceModel;
use App\Amazon\Components\Common\Helper;

use App\Amazon\Components\Template\CategoryAttributeCache;


class UploadedProductSyncHelper extends Base
{


    public function getListingData($data)
    {
        $date = date('d-m-Y');
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        $logFile = "amazonUploadedProductSync/{$userId}/{$date}.log";
        // Get the target shop id and shop details for the current user.
        $targetShopId = $this->di->getRequester()->getTargetId();
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $targetShop = $userDetails->getShop($targetShopId, $this->di->getUser()->id);

        // Prepare the payload for the Amazon listing request.
        $payload = [
            'sku'           => $data['seller-sku'],
            'shop_id'       => $targetShop['remote_shop_id'],
            'includedData'  => ['issues', 'attributes', 'summaries', 'offers', 'fulfillmentAvailability']
        ];

        // Send the request to Amazon using the common helper.
        $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
        $response = $commonHelper->sendRequestToAmazon('listing', $payload, 'GET');
        $this->di->getLog()->logContent('getListingResponse = ' . json_encode($response, JSON_PRETTY_PRINT), 'info', $logFile);

        if (isset($response['response']['summaries']) && empty($response['response']['summaries'])) {
            return [
                'success' => false,
                'message' => 'No summaries data fount in Listing'
            ];
        }

        // Build the container with default values.
        // Use current date/time for created_at and updated_at if no value is available.
        $container = [
            "amazonProductType"    => $response['response']['summaries'][0]['productType'],
        ];

        // Initialize variables that will be set if the response is successful.
        $allimages = false;
        $test = [];
        $schema = [];
        $browsenodeId = null;
        $testing = [];
        $shouldbarcodeValidate = false;
        // Check if the response indicates success.


        if (isset($response['success']) && $response['success']) {
            // Extract images from the response.
            // Only add description if it exists in the response.
            if (isset($response['response']['attributes']['product_description'][0]['value'])) {
                $container['description'] = $response['response']['attributes']['product_description'][0]['value'];
            }

            // Only add inventory_fulfillment_latency if it exists.
            if (isset($response['response']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'])) {
                $container['inventory_fulfillment_latency'] = $response['response']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'];
            }

            // Add title if available.
            if (isset($response['response']['summaries'][0]['itemName'])) {
                $container['title'] = $response['response']['summaries'][0]['itemName'];
            }
            $allimages = $this->extractImages($response['response']);
            $this->di->getLog()->logContent('allimages extracted ', 'info', $logFile);
            if (isset($response['response']['attributes']['supplier_declared_has_product_identifier_exemption'])) {
                $value = $response['response']['attributes']['supplier_declared_has_product_identifier_exemption'][0]['value'];
                $shouldbarcodeValidate = ($value === true || $value === 1) ? 1 : 0;
            }


            // Get product type definition. Use an empty string if productType is not set.
            $productType = isset($response['response']['summaries'][0]['productType']) ? $response['response']['summaries'][0]['productType'] : '';
            $schema = $this->getProductTypeDefinition($productType, $targetShop['remote_shop_id']);

            // Map the listing data for DB insertion.
            $test = $this->mapListingDataForDB($response['response'], isset($schema['schema']) ? $schema['schema'] : []);

            $this->di->getLog()->logContent('dataMapped for DB ', 'info', $logFile);


            // Retrieve ASIN if available.
            $asin = isset($response['response']['summaries'][0]['asin']) ? $response['response']['summaries'][0]['asin'] : '';

            // Get catalog/category attributes if ASIN is available.
            if (!empty($asin)) {
                $catalogItem = $this->getCategoryAttributes($targetShop['remote_shop_id'], $targetShopId, $asin);
                if (isset($catalogItem['response'][$asin]['summaries'][0]['browseClassification']['classificationId'])) {
                    $browsenodeId = $catalogItem['response'][$asin]['summaries'][0]['browseClassification']['classificationId'];
                }
                if (isset($catalogItem['response'][$asin]['classifications'])) {
                    $testing = $this->parseMarketplaceData($catalogItem['response'][$asin]['classifications']);
                }
            }
        }

        // Build the category_settings array only if required data is available.
        $category_settings = [];

        if (!empty($test) && !empty($schema) && isset($response['response']['summaries'][0]['marketplaceId'])) {
            $category_settings = [
                "attributes_mapping" => [
                    "jsonFeed"       => isset($test['attributes_mapping']['jsonFeed']) ? $test['attributes_mapping']['jsonFeed'] : [],
                    "language_tag"   => isset($schema['schema']['data']['$defs']['language_tag']) ? $schema['schema']['data']['$defs']['language_tag'] : '',
                    "marketplace_id" => $response['response']['summaries'][0]['marketplaceId']
                ],
                "browser_node_id"   => $browsenodeId,
                "displayPath"       => isset($testing['displayPath']) ? $testing['displayPath'] : [],
                "parentNodes"       => isset($testing['parentNodes']) ? $testing['parentNodes'] : [],
                "primary_category"  => isset($testing['primary_category']) ? $testing['primary_category'] : '',
                "sub_category"      => isset($testing['sub_category']) ? $testing['sub_category'] : '',
                "product_type"      => isset($schema['data']['data']['productType']) ? $schema['data']['data']['productType'] : ''
            ];
        }


        // Merge category_settings into the container.
        $container['category_settings'] = $category_settings;

        // If image extraction was successful, add main and additional images.
        if ($allimages) {
            if (isset($allimages['main_image'])) {
                $container['main_image'] = $allimages['main_image'];
            }
            if (isset($allimages['additional_images'])) {
                $container['additional_images'] = $allimages['additional_images'];
            }
        }

        if ($shouldbarcodeValidate) {
            $container['category_settings']['barcode_exemption'] = true;
        }

        return $container;
    }


    public function extractImages($data)
    {


        if (!isset($data['attributes'])) {
            return ["error" => "Invalid data format"];
        }

        $mainImage = $data['attributes']['main_product_image_locator'][0]['media_location'] ?? '';
        $additionalImages = [];

        for ($i = 1; $i <= 9; $i++) {
            $key = "other_product_image_locator_$i";
            if (isset($data['attributes'][$key])) {
                $additionalImages[] = $data['attributes'][$key][0]['media_location'];
            }
        }

        return [
            "main_image" => $mainImage,
            "additional_images" => $additionalImages
        ];
    }


    public function parseMarketplaceData($data)
    {
        $displayPath = [];
        $parentNodes = [];

        function extractPath($classification, &$displayPath, &$parentNodes)
        {
            if (isset($classification['parent'])) {
                extractPath($classification['parent'], $displayPath, $parentNodes);
            }

            // Always store classificationId
            $parentNodes[] = $classification['classificationId'];

            // Only store displayName if it's not "Categories"
            if ($classification['displayName'] !== 'Categories') {
                $displayPath[] = $classification['displayName'];
            }
        }

        foreach ($data as $entry) {
            if (!empty($entry['classifications'][0])) { // Process only the first classification dynamically
                extractPath($entry['classifications'][0], $displayPath, $parentNodes);
            }
        }

        // Set primary and sub-category dynamically
        $primaryCategory = $displayPath[0] ?? null; // First item in the hierarchy
        $subCategory = null;
        $displayPathCount = count($displayPath);
        if ($displayPathCount >= 2) {
            $subCategory = $displayPath[$displayPathCount - 2];
        } elseif ($displayPathCount === 1) {
            $subCategory = $displayPath[0];
        }
        // $subCategory = $displayPath[count($displayPath) - 2] ?? null; // Second last item


        return [
            "displayPath" => $displayPath,
            "parentNodes" => $parentNodes,
            "primary_category" => $primaryCategory,
            "sub_category" => $subCategory,
        ];
    }

    private function getProductTypeDefinition($productType, $remote_shop_id)
    {

        $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
        $params = [
            'shop_id' => $remote_shop_id,
            'product_type' => $productType ?? 'PRODUCT',
            'requirements' => 'LISTING',
            'product_type_version' => null, //this will use the latest version
            'use_seller_id' => true,
        ];
        $response = $commonHelper->sendRequestToAmazon('fetch-schema', $params, 'GET');

        if (!$response['success']) {
            return $response;
        }

        $categoryAttribute = $this->di
            ->getObjectManager()
            ->get(CategoryAttributeCache::class);

        $getSchemaContentResponse = $categoryAttribute->getSchemaContent($response['data']['schema']['link']['resource']);
        return [
            "schema" => $getSchemaContentResponse,
            "data" => $response
        ];
    }

    // public function mapListingDataForDB(array $listingData, array $productTypeDefinition): array
    // {

    //     $mappedData = [
    //         "sku" => $listingData["sku"],
    //         "attributes_mapping" => [
    //             "jsonFeed" => []
    //         ]
    //     ];

    //     $schemaProps = $productTypeDefinition["data"]["properties"] ?? [];

    //     // Keys we skip entirely
    //     $skip = [
    //         'main_product_image_locator',
    //         'other_product_image_locator_1', // … up to _10
    //         'other_product_image_locator_2',
    //         'other_product_image_locator_3',
    //         'other_product_image_locator_4',
    //         'other_product_image_locator_5',
    //         'other_product_image_locator_6',
    //         'other_product_image_locator_7',
    //         'other_product_image_locator_8',
    //         'other_product_image_locator_9',
    //         'other_product_image_locator_10',
    //         'supplier_declared_has_product_identifier_exemption',
    //         'product_description',
    //         'item_name',
    //         'recommended_browse_nodes'
    //     ];
    //     $fullSchema = $productTypeDefinition['data'];

    //     // Recursive mapper
    //     $mapValue = function ($value, array $schemaNode) use (&$mapValue, $fullSchema) {
    //         // Resolve $ref if present
    //         if (isset($schemaNode['$ref'])) {
    //             // e.g. "#/$defs/marketplace_id"
    //             $refPath  = explode('/', ltrim($schemaNode['$ref'], '#/'));
    //             $resolved = $fullSchema;
    //             foreach ($refPath as $segment) {
    //                 if (! isset($resolved[$segment])) {
    //                     $resolved = null;
    //                     break;
    //                 }
    //                 $resolved = $resolved[$segment];
    //             }
    //             if (is_array($resolved)) {
    //                 $schemaNode = $resolved;
    //             }
    //         }



    //         // ARRAY OF OBJECTS
    //         if (($schemaNode['type'] ?? null) === 'array'
    //             && isset($schemaNode['items']['type'])
    //             && $schemaNode['items']['type'] === 'object'
    //             && is_array($value)
    //         ) {
    //             $itemSchema = $schemaNode['items'];
    //             $out = [];
    //             foreach ($value as $item) {
    //                 $entry = [];
    //                 foreach ($itemSchema['properties'] ?? [] as $key => $subSchema) {
    //                     if (! array_key_exists($key, $item)) continue;
    //                     $entry[$key] = $mapValue($item[$key], $subSchema);
    //                 }
    //                 if ($entry) $out[] = $entry;
    //             }

    //             return $out;
    //         }

    //         // SIMPLE ARRAY (of scalars)
    //         if (($schemaNode['type'] ?? null) === 'array' && is_array($value)) {
    //             return [
    //                 "attribute_value" => "custom",
    //                 "type"            => "array",
    //                 "custom"          => $value
    //             ];
    //         }



    //         // OBJECT
    //         if (($schemaNode['type'] ?? null) === 'object' && is_array($value)) {
    //             $entry = [];

    //             foreach ($schemaNode['properties'] ?? [] as $key => $subSchema) {
    //                 if (! array_key_exists($key, $value)) continue;
    //                 $entry[$key] = $mapValue($value[$key], $subSchema);
    //             }

    //             return $entry;
    //         }

    //         // SCALAR
    //         if (is_bool($value)) {
    //             $type = "boolean";
    //         } elseif (is_numeric($value)) {
    //             $type = "number";
    //         } else {
    //             $type = "string";
    //         }



    //         // find enum list
    //         $enum = $schemaNode['enum'] ?? [];
    //         if (empty($enum) && isset($schemaNode['anyOf'])) {
    //             foreach ($schemaNode['anyOf'] as $opt) {
    //                 if (isset($opt['enum'])) {
    //                     $enum = $opt['enum'];
    //                     break;
    //                 }
    //             }
    //         }

    //         $indicator = in_array(strtolower((string)$value), array_map('strtolower', $enum), true)
    //             ? "recommendation" : "custom";


    //         return [
    //             "attribute_value" => $indicator,
    //             "type"            => $type,
    //             $indicator        => $value
    //         ];
    //     };

    //     foreach ($listingData["attributes"] as $attr => $vals) {
    //         if (! isset($schemaProps[$attr]) || in_array($attr, $skip, true)) {
    //             continue;
    //         }

    //         // special: item_package_dimensions
    //         if ($attr === "item_package_dimensions") {
    //             $dims = [];
    //             foreach ($vals as $d) {
    //                 $e = [];
    //                 foreach ($d as $dim => $cfg) {
    //                     $e[$dim] = [
    //                         "unit" => [
    //                             "attribute_value" => "recommendation",
    //                             "type"            => "string",
    //                             "recommendation"  => $cfg["unit"] ?? "centimeters"
    //                         ],
    //                         "value" => [
    //                             "attribute_value" => "custom",
    //                             "type"            => "number",
    //                             "custom"          => (float)($cfg["value"] ?? 0)
    //                         ]
    //                     ];
    //                 }
    //                 if ($e) $dims[] = $e;
    //             }
    //             if ($dims) {
    //                 $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $dims;
    //             }
    //             continue;
    //         }

    //         // special: purchasable_offer
    //         if ($attr === "purchasable_offer") {
    //             $offers = [];
    //             foreach ($vals as $offer) {
    //                 if (($offer['audience'] ?? '') !== 'ALL') continue;
    //                 $entry = [];
    //                 foreach ($offer as $k => $v) {
    //                     if (is_array($v) && isset($v[0]['schedule'])) {
    //                         // schedule array
    //                         $entry[$k] = [[
    //                             "schedule" => array_map(function ($s) {
    //                                 return [
    //                                     "value_with_tax" => [
    //                                         "attribute_value" => "custom",
    //                                         "type" => "number",
    //                                         "custom" => (float)($s["value_with_tax"] ?? 0)
    //                                     ]
    //                                 ];
    //                             }, $v[0]['schedule'])
    //                         ]];
    //                     } elseif ($k === "quantity_discount_plan" && is_array($v)) {
    //                         $entry[$k] = [[
    //                             "schedule" => array_map(function ($s) {
    //                                 return [
    //                                     "discount_type" => [
    //                                         "attribute_value" => "recommendation",
    //                                         "type" => "string",
    //                                         "recommendation" => $s["discount_type"] ?? "fixed"
    //                                     ],
    //                                     "levels" => array_map(function ($l) {
    //                                         return [
    //                                             "lower_bound" => [
    //                                                 "attribute_value" => "custom",
    //                                                 "type" => "integer",
    //                                                 "custom" => (int)($l["lower_bound"] ?? 0)
    //                                             ],
    //                                             "value" => [
    //                                                 "attribute_value" => "custom",
    //                                                 "type" => "number",
    //                                                 "custom" => (float)($l["value"] ?? 0)
    //                                             ]
    //                                         ];
    //                                     }, $s["levels"] ?? [])
    //                                 ];
    //                             }, $v[0]["schedule"] ?? [])
    //                         ]];
    //                     } else {
    //                         $entry[$k] = [
    //                             "attribute_value" => "recommendation",
    //                             "type" => "string",
    //                             "recommendation" => $v
    //                         ];
    //                     }
    //                 }
    //                 if ($entry) $offers[] = $entry;
    //             }
    //             if ($offers) {
    //                 $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $offers;
    //             }
    //             continue;
    //         }

    //         // generic: use schemaProps[$attr]["items"] as schema node
    //         $schemaNode = $schemaProps[$attr]["items"] ?? null;
    //         if (! $schemaNode) continue;




    //         // vals may be array of items or single item
    //         if (! is_array($vals)) continue;
    //         $out = [];
    //         foreach ($vals as $val) {
    //             $mapped = $mapValue($val, $schemaNode);
    //             if ($mapped) $out[] = $mapped;
    //         }
    //         if ($out) {
    //             $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $out;
    //         }
    //     }

    //     echo '<pre>';
    //     print_r(json_encode($mappedData,JSON_PRETTY_PRINT));
    //     die(__FILE__.'/LINE'.__LINE__);

    //     return $mappedData;
    // }

    public function mapListingDataForDB(array $listingData, array $productTypeDefinition): array
    {

        $mappedData = [
            "sku" => $listingData["sku"],
            "attributes_mapping" => [
                "jsonFeed" => []
            ]
        ];

        $schemaProps = $productTypeDefinition["data"]["properties"] ?? [];

        // Keys we skip entirely
        $skip = [
            'main_product_image_locator',
            'other_product_image_locator_1',
            'other_product_image_locator_2',
            'other_product_image_locator_3',
            'other_product_image_locator_4',
            'other_product_image_locator_5',
            'other_product_image_locator_6',
            'other_product_image_locator_7',
            'other_product_image_locator_8',
            'other_product_image_locator_9',
            'other_product_image_locator_10',
            'supplier_declared_has_product_identifier_exemption',
            'product_description',
            'item_name',
            'recommended_browse_nodes',
            'purchasable_offer',
            'fulfillment_availability'
        ];
        $fullSchema = $productTypeDefinition['data'];

        // Recursive mapper
        $mapValue = function ($value, array $schemaNode) use (&$mapValue, $fullSchema) {
            // Resolve $ref if present
            if (isset($schemaNode['$ref'])) {
                // e.g. "#/$defs/marketplace_id"
                $refPath  = explode('/', ltrim($schemaNode['$ref'], '#/'));
                $resolved = $fullSchema;
                foreach ($refPath as $segment) {
                    if (! isset($resolved[$segment])) {
                        $resolved = null;
                        break;
                    }
                    $resolved = $resolved[$segment];
                }
                if (is_array($resolved)) {
                    $schemaNode = $resolved;
                }
            }

            // ARRAY OF OBJECTS
            if (($schemaNode['type'] ?? null) === 'array'
                && isset($schemaNode['items']['type'])
                && $schemaNode['items']['type'] === 'object'
                && is_array($value)
            ) {
                $itemSchema = $schemaNode['items'];
                $out = [];
                foreach ($value as $item) {
                    $entry = [];
                    foreach ($itemSchema['properties'] ?? [] as $key => $subSchema) {
                        if (! array_key_exists($key, $item)) continue;
                        $entry[$key] = $mapValue($item[$key], $subSchema);
                    }
                    if ($entry) $out[] = $entry;
                }
                return $out;
            }

            // SIMPLE ARRAY (of scalars) - Modified to potentially return a single mapped array object
            if (($schemaNode['type'] ?? null) === 'array' && is_array($value) && (!isset($schemaNode['items']['type']) || $schemaNode['items']['type'] !== 'object')) {
                $mappedValues = [];
                $itemSchema = $schemaNode['items'] ?? []; // Get item schema for enum check
                $allRecommended = true;

                foreach ($value as $itemValue) {
                    // Apply scalar mapping logic with enum check
                    if (is_bool($itemValue)) {
                        $type = "boolean";
                    } elseif (is_numeric($itemValue)) {
                        $type = "number";
                    } else {
                        $type = "string";
                    }

                    $enum = $itemSchema['enum'] ?? [];
                    if (empty($enum) && isset($itemSchema['anyOf'])) {
                        foreach ($itemSchema['anyOf'] as $opt) {
                            if (isset($opt['enum'])) {
                                $enum = $opt['enum'];
                                break;
                            }
                        }
                    }

                    $indicator = in_array(strtolower((string)$itemValue), array_map('strtolower', $enum), true)
                        ? "recommendation" : "custom";

                    if ($indicator === "custom") {
                        $allRecommended = false;
                    }
                    $mappedValues[] = $itemValue;
                }

                if ($mappedValues) {
                    return [
                        "attribute_value" => $allRecommended ? "recommendation" : "custom",
                        "type"            => "array",
                        $allRecommended ? "recommendation" : "custom" => $mappedValues
                    ];
                }
                return [];
            }


            // Handling array of simple values within an object property - Modified
            if (is_array($value) && isset($schemaNode['type']) && $schemaNode['type'] === 'array' && isset($schemaNode['items'])) {
                $mappedValues = [];
                $itemSchema = $schemaNode['items'];
                $allRecommended = true;

                foreach ($value as $itemValue) {
                    $scalarResult = $mapValue($itemValue, $itemSchema);
                    if ($scalarResult['attribute_value'] === 'custom') {
                        $allRecommended = false;
                    }
                    $mappedValues[] = $scalarResult['custom'] ?? $scalarResult['recommendation'] ?? $itemValue;
                }

                if ($mappedValues) {
                    return [
                        "attribute_value" => $allRecommended ? "recommendation" : "custom",
                        "type"            => "array",
                        $allRecommended ? "recommendation" : "custom" => $mappedValues
                    ];
                }
                return [];
            }

            // OBJECT
            if (($schemaNode['type'] ?? null) === 'object' && is_array($value)) {
                $entry = [];

                foreach ($schemaNode['properties'] ?? [] as $key => $subSchema) {
                    if (! array_key_exists($key, $value)) continue;
                    $entry[$key] = $mapValue($value[$key], $subSchema);
                }

                return $entry;
            }

            // SCALAR
            if (is_bool($value)) {
                $type = "boolean";
            } elseif (is_numeric($value)) {
                $type = "number";
            } else {
                $type = "string";
            }

            // find enum list
            $enum = $schemaNode['enum'] ?? [];
            if (empty($enum) && isset($schemaNode['anyOf'])) {
                foreach ($schemaNode['anyOf'] as $opt) {
                    if (isset($opt['enum'])) {
                        $enum = $opt['enum'];
                        break;
                    }
                }
            }

            $indicator = in_array(strtolower((string)$value), array_map('strtolower', $enum), true)
                ? "recommendation" : "custom";

            return [
                "attribute_value" => $indicator,
                "type"            => $type,
                $indicator        => $value
            ];
        };

        foreach ($listingData["attributes"] as $attr => $vals) {
            if (! isset($schemaProps[$attr]) || in_array($attr, $skip, true)) {
                continue;
            }

            // special: item_package_dimensions
            if ($attr === "item_package_dimensions") {
                $dims = [];
                foreach ($vals as $d) {
                    $e = [];
                    foreach ($d as $dim => $cfg) {
                        $e[$dim] = [
                            "unit" => [
                                "attribute_value" => "recommendation",
                                "type"            => "string",
                                "recommendation"  => $cfg["unit"] ?? "centimeters"
                            ],
                            "value" => [
                                "attribute_value" => "custom",
                                "type"            => "number",
                                "custom"          => (float)($cfg["value"] ?? 0)
                            ]
                        ];
                    }
                    if ($e) $dims[] = $e;
                }
                if ($dims) {
                    $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $dims;
                }
                continue;
            }

            // special: purchasable_offer


            if ($attr === "purchasable_offer") {
                continue;
                $offers = [];
                foreach ($vals as $offer) {
                    if (($offer['audience'] ?? '') !== 'ALL') continue;
                    $entry = [];
                    foreach ($offer as $k => $v) {
                        if (is_array($v) && isset($v[0]['schedule'])) {
                            // schedule array
                            $entry[$k] = [[
                                "schedule" => array_map(function ($s) {
                                    return [
                                        "value_with_tax" => [
                                            "attribute_value" => "custom",
                                            "type" => "number",
                                            "custom" => (float)($s["value_with_tax"] ?? 0)
                                        ]
                                    ];
                                }, $v[0]['schedule'])
                            ]];
                        } elseif ($k === "quantity_discount_plan" && is_array($v)) {
                            $entry[$k] = [[
                                "schedule" => array_map(function ($s) {
                                    return [
                                        "discount_type" => [
                                            "attribute_value" => "recommendation",
                                            "type" => "string",
                                            "recommendation" => $s["discount_type"] ?? "fixed"
                                        ],
                                        "levels" => array_map(function ($l) {
                                            return [
                                                "lower_bound" => [
                                                    "attribute_value" => "custom",
                                                    "type" => "integer",
                                                    "custom" => (int)($l["lower_bound"] ?? 0)
                                                ],
                                                "value" => [
                                                    "attribute_value" => "custom",
                                                    "type" => "number",
                                                    "custom" => (float)($l["value"] ?? 0)
                                                ]
                                            ];
                                        }, $s["levels"] ?? [])
                                    ];
                                }, $v[0]["schedule"] ?? [])
                            ]];
                        } else {
                            $entry[$k] = [
                                "attribute_value" => "recommendation",
                                "type" => "string",
                                "recommendation" => $v
                            ];
                        }
                    }
                    if ($entry) $offers[] = $entry;
                }
                if ($offers) {
                    $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $offers;
                }
                continue;
            }

            // generic: use schemaProps[$attr]["items"] as schema node
            $schemaNode = $schemaProps[$attr]["items"] ?? null;
            if (! $schemaNode) continue;


            // vals may be array of items or single item
            if (! is_array($vals)) continue;
            $out = [];
            foreach ($vals as $val) {
                $mapped = $mapValue($val, $schemaNode);
                if ($mapped) $out[] = $mapped;
            }
            if ($out) {
                $mappedData["attributes_mapping"]["jsonFeed"][$attr] = $out;
            }
        }

        return $mappedData;
    }







    public function getCategoryAttributes($remote_shop_id, $targetShopId, $asin)
    {
        try {
            $params = [
                'id' => [$asin],
                'shop_id' => (string)$remote_shop_id,
                'home_shop_id' => (string)$targetShopId,
                'id_type' => 'ASIN',
                'included_data' => 'attributes,classifications,dimensions,identifiers,images,productTypes,summaries,relationships'
            ];

            $response = $this->di->getObjectManager()->get("App\Amazon\Components\Common\Helper")->sendRequestToAmazon('product', $params, 'GET');

            if (isset($response['success']) && $response['success']) {
                return $response;
            }
            return [
                'success' => false,
                'error' => "not able to fetch data"
            ];

            throw new \Exception("Amazon API request failed or returned an error.");
        } catch (\Throwable $e) {

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function saveData($productData, $productDataFromAmazon, $listingData, $params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);

        $date = date('d-m-Y');
        if (isset($params['data']['user_id'])) {
            $userId = $params['data']['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        $logFile = "amazonUploadedProductSync/{$userId}/{$date}.log";

        if (isset($listingData['fulfillment-channel']) && ($listingData['fulfillment-channel'] != 'DEFAULT' && $listingData['fulfillment-channel'] != 'default')) {
            $channel = 'FBA';
        } else {
            $channel = 'FBM';
        }
        $objectManager = $this->di->getObjectManager();
        $validator = $objectManager->get(SourceModel::class);
        $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
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

        $this->di->getLog()->logContent('status = ' . $status, 'info', $logFile);

        if (isset($productData['type']) && isset($productData['visibility']) && $productData['type'] == "simple" && $productData['visibility'] == 'Catalog and Search') {
            //in case of simple type product
            $this->di->getLog()->logContent('simple product', 'info', $logFile);

            if ($asin != '') {
                // update asin on product container
                $asinData = [
                    [
                        'user_id' => $userId,
                        'target_marketplace' => 'amazon',
                        'shop_id' => (string)$params['target']['shopId'],
                        'container_id' => $productData['container_id'],
                        'source_product_id' => $productData['source_product_id'],
                        'asin' => $asin,
                        'sku' => $listingData['seller-sku'],
                        "shopify_sku" => $productData['sku'],
                        'matchedwith' => 'manual',
                        'status' => $status,
                        'target_listing_id' => (string)$listingData['_id'],
                        'source_shop_id' => $params['source']['shopId'],
                        'fulfillment_type' => $channel
                    ]
                ];

                if (isset($productDataFromAmazon['amazonProductType'])) {
                    $asinData[0]['amazonProductType'] = $productDataFromAmazon['amazonProductType'];
                }
                if (isset($productDataFromAmazon['title'])) {
                    $asinData[0]['title'] = $productDataFromAmazon['title'];
                }
                if (isset($productDataFromAmazon['category_settings'])) {
                    $asinData[0]['category_settings'] = $productDataFromAmazon['category_settings'];
                }
                if (isset($productDataFromAmazon['main_image'])) {
                    $asinData[0]['main_image'] = $productDataFromAmazon['main_image'];
                }
                if (isset($productDataFromAmazon['additional_images'])) {
                    $asinData[0]['additional_images'] = $productDataFromAmazon['additional_images'];
                }
                if (isset($productDataFromAmazon['description'])) {
                    $asinData[0]['description'] = $productDataFromAmazon['description'];
                }
                if (isset($productDataFromAmazon['inventory_fulfillment_latency'])) {
                    $asinData[0]['inventory_fulfillment_latency'] = (string) $productDataFromAmazon['inventory_fulfillment_latency'];
                }
                if (isset($productDataFromAmazon['category_settings']['barcode_exemption']) && $productDataFromAmazon['category_settings']['barcode_exemption']) {
                    $asinData[0]['dont_validate_barcode'] = $productDataFromAmazon['category_settings']['barcode_exemption'];
                }
                $params['dont_validate'] = true;

                //validate product for unique sku in marketplace
                $valid = $validator->validateSaveProduct($asinData, $userId, $params);
                $this->di->getLog()->logContent('data = ' . print_r($asinData, true), 'info', 'ashishTestMatch.log');

                if (isset($valid['success']) && !$valid['success']) {
                    return ['success' => false, 'message' => "Product's seller-sku is not unique in marketplace."];
                }
            }
        } elseif (isset($productData['type']) && isset($productData['visibility']) && $productData['type'] == "simple" && $productData['visibility'] == 'Not Visible Individually') {
            //in case of variation child (in this case we save listing data in child and also add data in parent for that we need to get parent data from DB)
            $this->di->getLog()->logContent('variation childct', 'info', $logFile);

            $query = ['user_id' => $userId, "source_product_id" => $productData['container_id'], '$or' => [
                ["shop_id" => $params['source']['shopId']],
                ["shop_id" => $params['target']['shopId']]
            ]];
            $productDataForParent = $helper->getproductbyQuery($query);
            $productDataForParent = json_decode(json_encode($productDataForParent), true);
            $EditedData = array_filter($productDataForParent, function ($product) {
                return isset($product['target_marketplace']) &&
                    $product['target_marketplace'] === 'amazon' &&
                    isset($product['category_settings']['attributes_mapping']['jsonFeed']);
            });
            $parentData = [];
            if (empty($EditedData)) {
                $parentData = [
                    'user_id' => $userId,
                    'target_marketplace' => 'amazon',
                    'shop_id' => (string)$params['target']['shopId'],
                    'container_id' => $productData['container_id'],
                    'source_product_id' => $productData['container_id'],
                    'source_shop_id' => $params['source']['shopId'],
                    'fulfillment_type' => $channel
                ];


                if (isset($productDataFromAmazon['amazonProductType'])) {
                    $parentData['amazonProductType'] = $productDataFromAmazon['amazonProductType'];
                }
                if (isset($productDataFromAmazon['category_settings'])) {
                    $parentData['category_settings'] = $productDataFromAmazon['category_settings'];
                }
            }

            $childData = [
                'user_id' => $userId,
                'target_marketplace' => 'amazon',
                'shop_id' => (string)$params['target']['shopId'],
                'container_id' => $productData['container_id'],
                'source_product_id' => $productData['source_product_id'],
                'asin' => $asin,
                'sku' => $listingData['seller-sku'],
                "shopify_sku" => $productData['sku'],
                'matchedwith' => 'manual',
                'status' => $status,
                'target_listing_id' => (string)$listingData['_id'],
                'source_shop_id' => $params['source']['shopId'],
                'fulfillment_type' => $channel
            ];
            if (isset($productDataFromAmazon['title'])) {
                $childData['title'] = $productDataFromAmazon['title'];
            }

            if (isset($productDataFromAmazon['category_settings'])) {

                // Retrieve the variation theme
                $variationThemeKey = strtolower($productDataFromAmazon['category_settings']['attributes_mapping']['jsonFeed']['variation_theme'][0]['name']['recommendation'] ?? '');

                // Check if variation theme is set and not empty
                if (!empty($variationThemeKey)) {
                    // Assign the edited_variant_jsonFeed to childData
                    $childData['edited_variant_jsonFeed'] = $productDataFromAmazon['category_settings']['attributes_mapping']['jsonFeed'][$variationThemeKey] ?? null;
                    // Remove the variation theme entry from parentData
                    // unset($parentData['category_settings']['attributes_mapping']['jsonFeed'][$variationThemeKey]);
                }
                // $childData['edited_variant_jsonFeed'] = $productDataFromAmazon['category_settings']['attributes_mapping']['jsonFeed'];
                // $childData['category_settings']['barcode_exemption'] = $productDataFromAmazon['category_settings']['barcode_exemption'];
                // $childData['category_settings']['browser_node_id'] = $productDataFromAmazon['category_settings']['browser_node_id'];
                // $childData['category_settings']['displayPath'] = $productDataFromAmazon['category_settings']['displayPath'];
                // $childData['category_settings']['parentNodes'] = $productDataFromAmazon['category_settings']['parentNodes'];
                // $childData['category_settings']['primary_category'] = $productDataFromAmazon['category_settings']['primary_category'];
                // $childData['category_settings']['product_type'] = $productDataFromAmazon['category_settings']['product_type'];
                // $childData['category_settings']['sub_category'] = $productDataFromAmazon['category_settings']['sub_category'];
            }
            if (isset($productDataFromAmazon['main_image'])) {
                $childData['main_image'] = $productDataFromAmazon['main_image'];
            }
            if (isset($productDataFromAmazon['additional_images'])) {
                $childData['additional_images'] = $productDataFromAmazon['additional_images'];
            }

            $asinData = [$childData];

            if (!empty($parentData)) {
                $asinData[] = $parentData;
            }
        } else if (isset($productData['type']) && isset($productData['visibility']) && $productData['type'] == "variation" && $productData['visibility'] == 'Catalog and Search') {
            //in case of

            $this->di->getLog()->logContent('parent', 'info', $logFile);

            $asinData = [[
                'user_id' => $userId,
                'target_marketplace' => 'amazon',
                'shop_id' => (string)$params['target']['shopId'],
                'container_id' => $productData['container_id'],
                'source_product_id' => $productData['source_product_id'],
                'asin' => $asin,
                'sku' => $listingData['seller-sku'],
                "shopify_sku" => $productData['sku'],
                'matchedwith' => 'manual',
                'status' => $status,
                'target_listing_id' => (string)$listingData['_id'],
                'source_shop_id' => $params['source']['shopId'],
                'fulfillment_type' => $channel
            ]];
            if (isset($productDataFromAmazon['amazonProductType'])) {
                $asinData[0]['amazonProductType'] = $productDataFromAmazon['amazonProductType'];
            }
            if (isset($productDataFromAmazon['title'])) {
                $asinData[0]['title'] = $productDataFromAmazon['title'];
            }
            if (isset($productDataFromAmazon['category_settings'])) {
                $asinData[0]['category_settings'] = $productDataFromAmazon['category_settings'];
            }
            if (isset($productDataFromAmazon['main_image'])) {
                $asinData[0]['main_image'] = $productDataFromAmazon['main_image'];
            }
            if (isset($productDataFromAmazon['additional_images'])) {
                $asinData[0]['additional_images'] = $productDataFromAmazon['additional_images'];
            }
            if (isset($productDataFromAmazon['description'])) {
                $asinData[0]['description'] = $productDataFromAmazon['description'];
            }

            $params['dont_validate'] = true;

            $valid = $validator->validateSaveProduct($asinData, $userId, $params);
            // $this->di->getLog()->logContent('data = ' . print_r($asinData, true), 'info', 'ashishTestMatch.log');

            if (isset($valid['success']) && !$valid['success']) {
                return ['success' => false, 'message' => "Product's seller-sku is not unique in marketplace."];
            }

            if (!empty($parentData)) {
                $asinData[] = $parentData;
            }
        }

        $this->di->getLog()->logContent('data = ' . json_encode($asinData, JSON_PRETTY_PRINT), 'info', $logFile);
        $editHelper = $objectManager->get('\App\Connector\Models\Product\Edit');
        $params['dont_validate'] = true;



        $dataResponse = $editHelper->saveProduct($asinData, $userId, $params);
        if (isset($dataResponse['success']) && $dataResponse['success']) {
            $this->di->getLog()->logContent('dataResponse = ' . json_encode($dataResponse, JSON_PRETTY_PRINT), 'info', $logFile);
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
                    'sku' => $productData['sku'],
                    'shop_id' => $productData['shop_id']
                ]
            ];
            $amazonCollection->updateOne(['_id' => $listingData['_id']], ['$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1, 'manual_unlinked' => 1, 'manual_unlink_time' => 1], '$set' => $matchedData]);
            // initiate inventory_sync after linking
            $reportHelper = $objectManager->get(\App\Amazon\Components\Report\Helper::class);
            $reportHelper->inventorySyncAfterLinking([$productData['source_product_id']], $params);
            return ['success' => true, 'message' => 'Amazon product has been successfully linked with selected Shopify product.'];
        } else {
            return ['success' => false, 'message' => $dataResponse['message']];
        }
    }
}
