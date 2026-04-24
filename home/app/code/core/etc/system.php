<?php
return [
            'tabs' => [
                'global' => [
                        'title' => 'configuration',
                        'code' => 'global',
                        'child' => false,
                    ],

            ],

            'tabs_content' => [
                                'global' => [
                                    'title' => 'global',
                                    'code' => 'global',
                                    'data'=>[
                                        /*'group1'=>[
                                        'id' => 'general_configuration_form',
                                        'group' => 'Configuration Details',
                                            'formJson' => [
                                            [
                                                'attribute' => 'Auth',
                                                'key' => 'config[global][global/auth]',
                                                'field' => 'textfield',
                                                'data' => [
                                                  'type' => 'text',
                                                  'value' => '',
                                                  'placeholder' => 'example.myshopify.com',
                                                  'required' => true,
                                                                                    ],
                                                         ],
                                            ],
                                        ]*/
                                    ],
                                    'shopify' => [
                                        'title' => 'shopify',
                                        'code' => 'shopify',
                                        'data'=>[
                                            /*'group1'=>[
                                            'id' => 'general_configuration_form',
                                            'group' => 'Configuration Details',
                                                'formJson' => [
                                                [
                                                    'attribute' => 'Auth',
                                                    'key' => 'config[global][global/auth]',
                                                    'field' => 'textfield',
                                                    'data' => [
                                                      'type' => 'text',
                                                      'value' => '',
                                                      'placeholder' => 'example.myshopify.com',
                                                      'required' => true,
                                                                                        ],
                                                             ],
                                                ],
                                            ]*/
                                        ],
                                    ],
                                    'walmart' => [
                                        'title' => 'walmart',
                                        'code' => 'walmart',
                                        'data'=>[
                                            /*'group1'=>[
                                            'id' => 'general_configuration_form',
                                            'group' => 'Configuration Details',
                                                'formJson' => [
                                                [
                                                    'attribute' => 'Auth',
                                                    'key' => 'config[global][global/auth]',
                                                    'field' => 'textfield',
                                                    'data' => [
                                                      'type' => 'text',
                                                      'value' => '',
                                                      'placeholder' => 'example.myshopify.com',
                                                      'required' => true,
                                                                                        ],
                                                             ],
                                                ],
                                            ]*/
                                        ]
                                    ]
                                ],
                            ],
                            
        ];
