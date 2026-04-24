<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class FunctionCall extends BaseMongo
{
    public $sectionList = ["product"];

    public $callSchema = [
        "product" => [[
            "type" => "function",
            "function" => [
                "name" => "getRefineProducts",
                "description" => "Returns a list of products based on the specified filters when user ask for products.",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "filter" => [
                            "type" => "object",
                            "description" => "A set of key-value pairs that specify the criteria for filtering products",
                            "properties" => [
                                "items.title" => [
                                    "type" => "object",
                                    "description" => "Filter condition for the product title or name",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "description" => "Condition type (1: Equals, 2: Not Equals, 3: Contains, 4: Does not Contain)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "description" => "Text to match against the title"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "items.sku" => [
                                    "type" => "object",
                                    "description" => "Filter condition for the SKU",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "description" => "Condition type (1: Equals, 2: Not Equals, 3: Contains, 4: Does not Contain)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "description" => "Text to match against the SKU"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "brand" => [
                                    "type" => "object",
                                    "description" => "Filter condition for the brand",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "description" => "Condition type (1: Equals, 2: Not Equals, 3: Contains, 4: Does not Contain)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "description" => "Text to match against the brand"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "barcodeAsin" => [
                                    "type" => "object",
                                    "description" => "Filter condition for the barcode or ASIN",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "description" => "Condition type (1: Equals, 2: Not Equals, 3: Contains, 4: Does not Contain)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "description" => "Text to match against the barcode or ASIN"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "items.type" => [
                                    "type" => "object",
                                    "description" => "Type of the product (fixed values)",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "enum" => [
                                                "1"
                                            ],
                                            "default" => "1",
                                            "description" => "Condition type (1: Equals)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "enum" => [
                                                "simple",
                                                "variation"
                                            ],
                                            "description" => "Fixed types of products"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "items.listingTitle" => [
                                    "type" => "object",
                                    "description" => "Health status of the product listing (fixed values)",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "enum" => [
                                                "1"
                                            ],
                                            "default" => "1",
                                            "description" => "Condition type (1: Equals)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "enum" => [
                                                "HIGH",
                                                "MEDIUM",
                                                "LOW"
                                            ],
                                            "description" => "Fixed health statuses"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ],
                                "cif_amazon_multi_activity" => [
                                    "type" => "object",
                                    "description" => "Activity status of the product (fixed values)",
                                    "properties" => [
                                        "value" => [
                                            "type" => "string",
                                            "enum" => [
                                                "1"
                                            ],
                                            "default" => "1",
                                            "description" => "Condition type (1: Equals)"
                                        ],
                                        "text" => [
                                            "type" => "string",
                                            "enum" => [
                                                "error",
                                                "process_tags",
                                                "upload",
                                                "draft"
                                            ],
                                            "description" => "Fixed activity statuses"
                                        ]
                                    ],
                                    "required" => [
                                        "value",
                                        "text"
                                    ]
                                ]
                            ],
                            "additionalProperties" => false
                        ],
                        "path" => [
                            "type" => "string",
                            "description" => "return path - '\App\Connector\Models\ProductContainer'"
                        ],
                        "count" => [
                            "type" => 'string',
                            "description" => "return 20"
                        ],
                        "productOnly" => [
                            "type" => "string",
                            "description" => "return true"
                        ],
                        "sortBy" => [
                            "type" => "string",
                            "description" => "return _id"
                        ],
                        "frontend" => [
                            "type" => "string",
                            "description" => "listing"
                        ]
                    ],
                    "required" => [
                        "filter",
                        "path",
                        "count",
                        "productOnly",
                        "sortBy",
                        "frontend"
                    ]
                ]
            ]
        ]],
        "notFound" => [[
            "type" => "function",
            "function" => [
                "name" => "nothingmatched",
                "description" => "this default function which is triggered if input by user does not matches any functions params",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "message" => [
                            "type" => "string",
                            "description" => "return - 'no information found'"
                        ],
                        "path" => [
                            "type" => "string",
                            "description" => "returns only with - 'App\Connector\Components\AI\FunctionCall'"
                        ],
                        "section" => [
                            "type" => "string",
                            "description" => "return possible section from these -'product | order | settings | none'"
                        ]
                    ],
                    "required" => ["message", "path"]
                ]
            ]
        ]]
    ];


    public function nothingmatched($data)
    {
        return ['message' => $data['message'] ?? "nothing found!!!", "toast" => true];
    }

    public function functionCall($data)
    {
        if (empty($data['prompt'])) {
            return ['success' => false, 'message' => "prompt missing"];
        }

        if (empty($data['section'])) {
            return ['success' => false, 'message' => "section missing"];
        }

        if (!in_array($data['section'], $this->sectionList)) {
            return ['success' => false, 'message' => "section not available"];
        }

        $helper = new \App\Smartlister\Api\RemoteSmartlister;
        $res = $helper->getRemoteResponse([
            'url' => "api/v1/ultravision/func/process",
            'payload' => [
                'prompt' => $data['prompt'],
                'tools' => array_merge($this->callSchema[$data['section']], $this->callSchema['notFound'])
            ]
        ], "POST");
        if (!empty($res['data'])) {
            // $res = $res['res'];
            if ($res['success'] && $res['data'] && $res['data']['message'] && $res['data']['message']['tool_calls']) {
                $tool_calls = $res['data']['message']['tool_calls'];
                if (!empty($tool_calls)) {
                    $function = $tool_calls[0] && !empty($tool_calls[0]['function']) ? $tool_calls[0]['function'] : false;
                    if ($function) {
                        $funcName = $function['name'];
                        $args = json_decode($function['arguments']);
                        $response = [];
                        $path = $args->path ?? $args['path'] ?? false;
                        if ($path) {
                            if ($this->di->getObjectManager()->get($path) && (method_exists($this->di->getObjectManager()->get($path), $funcName))) {
                                $payload = json_decode(json_encode($args), true);
                                if ($payload['filter']) {
                                    $tmp =  $payload['filter'];
                                    unset($payload['filter']);
                                    foreach ($tmp as $k => $v) {
                                        $payload["filter"][$k][$v['value']] = $v['text'];
                                    }
                                }

                                unset($payload['path']);
                                $response = $this->di->getObjectManager()->get($path)->$funcName($payload);
                                $response['toast'] = !empty($response['toast']) ? $response['toast'] : false;
                            }
                        }

                        return ['success' => true, 'data' => [
                            'name' => $funcName,
                            'payload' => $payload,
                            'response' => $response,
                            'path' => $path
                        ]];
                    }
                }
            }
        }

        return ['success' => false, 'message' => "unable to generate data"];
    }
}
