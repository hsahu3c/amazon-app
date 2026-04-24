<?php

namespace App\Connector\Components\AI\Prompts\Instructions\Amazon;

class DescriptionData
{
    public function getDescriptionInstruction($key)
    {
        return $this->description[$key] ?? [
            'format' => "description properly formatted with HTML . Make sure that max characters count is less than 1700 characters, summrize it in 1700 characters. Provide the HTML in single line ",
            'instructions' => '
                Use User Provided input to generate description.
                Do not include your seller name, e-mail address, website URL, or any company-specific information. 
                 ',
            'additionals' => [
                '1) Just provide description no additional info needed.',
            ]
        ];
    }

    public $description = [
        'default' => [
            'format' => "description properly formatted with HTML . Make sure that max characters count is less than 1700 characters, summrize it in 1700 characters. Provide the HTML in single line ",
            'instructions' => '
                Use User Provided input to generate description.
                Do not include your seller name, e-mail address, website URL, or any company-specific information. 
                 ',
            'additionals' => [
                '1) Just provide description no additional info needed.',
            ]
        ],
        // 'clothing' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'food' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "electronics" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "baby" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "beauty" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "pet" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'toys' =>  [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'sports' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'automotive' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'industrial' =>[
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'automotive' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // 'office' => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "eyewear" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "jewellery" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "home" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "watch" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "entertainment" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],
        // "books" => [
        //     'format' => "",
        //     'instructions' => '',
        //     'additionals' => []
        // ],

    ];
}
