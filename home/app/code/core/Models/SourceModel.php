<?php

namespace App\Core\Models;

class SourceModel extends \App\Core\Components\Base implements SourceModelInterface
{
    public $userconfig;

    public function _construct()
    {
        $userconfig = $this->di->getObjectManager()
            ->get('App\Core\Models\User')->load($this->di->getUser()->getId());
        $this->userconfig = $userconfig;
    }

    public function getAdditionalData()
    {
    }

    public function getConfigurationForm($subtab = false)
    {

        if ($subtab) {
        } else {
            $data = [[
                'id' => 'shopify_installation_form',
                'group' => 'Installation Details',
                'formJson' => [
                    [
                        'attribute' => 'Auth',
                        'key' => 'global/auth',
                        'field' => 'textfield',
                        'data' => [
                            'type' => 'text',
                            'value' => $this->userconfig->getConfigByPath('global/auth')
                                ?
                                $this->userconfig->getConfigByPath('global/auth')['value'] : '',
                            'placeholder' => 'example.myshopify.com',
                            'required' => true,
                        ],
                    ],
                ],
            ]];
        }
        return $data;
    }

    public function getInstallationForm()
    {
        $url = $this->di->getUrl()->get() . 'shopify/site/login';
        $data = [
            'post_type' => 'external',
            'method' => 'post',
            'action' => $url,
            'schema' => [
                0 => [
                    'id' => 'shopify_installation_form',
                    'group' => 'Installation Details',
                    'formJson' => [
                        0 => [
                            'attribute' => 'Shop',
                            'key' => 'shop',
                            'field' => 'textfield',
                            'data' => [
                                'type' => 'text',
                                'value' => '',
                                'placeholder' => 'example.myshopify.com',
                                'required' => true,
                            ],
                        ],
                        1 => [
                            'attribute' => '',
                            'key' => 'bearer',
                            'field' => 'textfield',
                            'data' => [
                                'type' => 'hidden',
                                'value' => $this->di->getRegistry()->getToken(),
                                'required' => false,
                            ],
                        ],
                    ],
                ],

            ],
        ];

        return $data;
    }

    public function getSubTab()
    {
        return false;
    }

    public function getShops($userId = false, $getDetails = false)
    {
        return [];
    }
}
