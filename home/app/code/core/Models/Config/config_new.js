    const a = [{
            "type": "profile",
            "name": "testing Categorry",
            "query": " ( price < 100 ) ",
            "overWriteExistingProducts": 1,
            "category_id": "CBT416865", // cedcommerce
            "data": {}, // template,
            "active": 1, // for disabled the profile 
            "attributes_mapping_id": "123", // top cedcommerce
            "targets": {
                "mercado_cbt": {
                    "skip_query": "( qty > 0 )",
                    "data": {}, // template
                    "category_id": "CBT416865", // mecado
                    "attributes_mapping_id": "123", // attribute mercado
                    "shops": [{
                            "shop_id": "110",
                            "active": 1, // for shop closing 
                            "data": {
                                "85": { //warehouse_id
                                    "data_type": "template",
                                    "id": {
                                        "price_template": "60cedb9675e9a45807019932"
                                    }
                                },
                                "86": {
                                    "data_type": "template",
                                    "id": {
                                        "price_template": "60ffcaa4ffd03b79756564b2"
                                    }
                                }
                            },
                            "attributes_mapping_id": "123",
                            "warehouses": [{
                                    "warehouse_id": "85",
                                    "active": 1,
                                    "data": {
                                        "85": {
                                            "data_type": "template",
                                            "id": {
                                                "price_template": "60cedb9675e9a45807019932"
                                            }
                                        },
                                        "86": {
                                            "data_type": "template",
                                            "id": {
                                                "price_template": "60ffcaa4ffd03b79756564b2"
                                            }
                                        }
                                    },
                                    "attributes_mapping_id": "123",
                                    "sources": {
                                        "shopify": [{
                                            "shop_id": "1",
                                            "id": "xyz"
                                        }],
                                    }
                                },
                                {
                                    "warehouse_id": "86",
                                    "active": 1,
                                    "data": [{
                                            "data_type": "template",
                                            "id": {
                                                "price_template": "60cedb9675e9a45807019932"
                                            }
                                        },
                                        {
                                            "data_type": "template",
                                            "id": {
                                                "price_template": "60ffcaa4ffd03b79756564b2"
                                            }
                                        }
                                    ],
                                    "attributes_mapping_id": "123",
                                    "source_id": {
                                        "shopify": [{
                                            "shop_id": "1",
                                            "id": "xyz"
                                        }]
                                    }
                                },
                            ]
                        },
                        {
                            "shop_id": "199",
                            "active": 1,
                            "data": {
                                "311": {
                                    "data_type": "template",
                                    "id": {}
                                },
                                "312": {
                                    "data_type": "template",
                                    "id": {}
                                }
                            },
                            "attributes_mapping_id": "123",
                            "warehouses": {}
                        }
                    ],
                },
                "ebay": {

                },
                "etsy": {

                }
            },
            "id": "6119fef61c4e1356bf726a43",
            "update": true
        },
        {
            "type": "source_data",
            "id": "xyz",
            "active": 1,
            "profile_id": "", //
            "warehouses": {
                "1": {
                    "warehouse_id": "1",
                    "data": {
                        "85": {
                            "data_type": "template",
                            "id": {
                                "price_template": "60cedb9675e9a45807019932"
                            }
                        },
                        "86": {
                            "data_type": "template",
                            "id": {
                                "price_template": "60ffcaa4ffd03b79756564b2"
                            }
                        }
                    },
                    "active": 1,
                    "attributes_mapping_id": "123"
                },
                "2": {
                    "warehouse_id": "2",
                    "data": {
                        "85": {
                            "data_type": "template",
                            "id": {
                                "price_template": "60cedb9675e9a45807019932"
                            }
                        },
                        "86": {
                            "data_type": "template",
                            "id": {
                                "price_template": "60ffcaa4ffd03b79756564b2"
                            }
                        }
                    },
                    "active": 1,
                    "attributes_mapping_id": "123"
                }
            }
        },
        {
            "type": "attribute_mapping",
            "id": "123",
            "data": {
                "brand": {
                    "type": "attribute",
                    "value": "brand"
                },
                "model": {
                    "type": "attribute",
                    "value": "brand"
                },
                "gtin": {
                    "type": "attribute",
                    "value": "barcode"
                },
                "package_height": {
                    "type": "attribute",
                    "value": "height",
                    "number_unit": "cm"
                },
                "package_width": {
                    "type": "attribute",
                    "value": "width",
                    "number_unit": "cm"
                },
                "package_length": {
                    "type": "attribute",
                    "value": "length",
                    "number_unit": "cm"
                },
                "package_weight": {
                    "type": "attribute",
                    "value": "weight",
                    "number_unit": "kg"
                },
                "warranty_type": {
                    "type": "predefined",
                    "value": "2230280",
                    "name": "Seller warranty"
                },
                "warranty_time": {
                    "type": "fixed",
                    "value": "0",
                    "number_unit": "months"
                }
            }
        }
    ]

    [{
            "_id": ObjectId("60ba84efe71f7db42af07a3a"),
            "container_id": "4597647671433",
            "group_id": "4597647671433",
            "shops": [{
                "category_settings": {
                    "primary_category": "Beauty",
                    "sub_category": "beautymisc",
                    "browser_node_id": "6344398011",
                    "barcode_exemption": false,
                    "attributes_mapping": {
                        "required_attribute": [{
                                "amazon_attribute": "external_product_id",
                                "shopify_attribute": "description",
                                "recommendation": "",
                                "custom_text": ""
                            },
                            {
                                "amazon_attribute": "manufacturer",
                                "shopify_attribute": "weight",
                                "recommendation": "",
                                "custom_text": ""
                            },
                            {
                                "amazon_attribute": "part_number",
                                "shopify_attribute": "inventory_item_id",
                                "recommendation": "",
                                "custom_text": ""
                            }
                        ]
                    }
                }
            }],
            "user_id": "61e9058c11bd1940736da7a2",
            "profile_id": "118",
            "profile_name": "shirt",
            "source_id": "32419581395081",
            "target_marketplace": "amazon"
        }

    ]