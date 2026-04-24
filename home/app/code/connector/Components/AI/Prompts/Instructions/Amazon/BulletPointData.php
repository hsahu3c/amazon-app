<?php

namespace App\Connector\Components\AI\Prompts\Instructions\Amazon;

class BulletPointData
{
    public function getBulletPointInstruction($key)
    {
        return $this->bulletPoint[$key] ?? [
            'format' => " Repeat the important information from the title
                Begin each bullet point with Capital letter
                Write sentences in fragments and do not use full stop or exclaimation mark or any symbol that shows the ending of sentence
                Do not include promotional or pricing information
                Do not include shipper information",
            'instructions' => '
                Bullet point must be of atleast 80 characters.
                Maintain a consistent order.
                If your first bullet point of your first product is material, keep that same order for all your products.
                Reiterate important information from the title and description
                Write all numbers as numerals

                Do Not Use hyphens, symbols, periods, or exclamation points just separate it with \n.
                be as specific as possible with product features and attributes

                Use the user provided data to generate bullet points.

                example response : ```bullet1 \n bullet2 \n bullet3 \n .... and so on.````
                ',
            'additionals' => []
        ];
    }

    public $bulletPoint = [
        'default' => [
            'format' => " Repeat the important information from the title
                Begin each bullet point with Capital letter
                Write sentences in fragments and do not use full stop or exclaimation mark or any symbol that shows the ending of sentence
                Do not include promotional or pricing information
                Do not include shipper information",
            'instructions' => '
                Bullet point must be of atleast 80 characters.
                Maintain a consistent order.
                If your first bullet point of your first product is material, keep that same order for all your products.
                Reiterate important information from the title and description
                Write all numbers as numerals

                Do Not Use hyphens, symbols, periods, or exclamation points just separate it with \n.
                be as specific as possible with product features and attributes

                Use the user provided data to generate atleast 5 bullet points.

            example response : ```bullet1 \n bullet2 \n bullet3 \n bullet4 \n bullet5 \n .... and so on.```` ',
            'additionals' => []
        ],
        'clothing' => [
            'format' => "
            Material (Fabric Type)
            Import Designation (Made in the USA, Imported, Made in the USA and Imported, Made in the USA or Imported)
            Fur Description Attributes (required if items contain real fur)
            Care instructions
            Fit information
            Quantity in package
            Dimensions
            Country of Manufacture/Origin
            ",
            'instructions' => '
            Bullet point must be of atleast 80 characters.
            Maintain a consistent order.
            If your first bullet point of your first product is material, keep that same order for all your products.
            Reiterate important information from the title and description
            Write all numbers as numerals

            Do Not Use hyphens, symbols, periods, or exclamation points just separate it with \n.
            be as specific as possible with product features and attributes

            Use the user provided data to generate atleast 5 bullet points.

            example response : ```bullet1 \n bullet2 \n bullet3 \n bullet4 \n bullet5 \n .... and so on.````',
            'additionals' => []
        ],
        'beauty' => [
            'format' => "Product details and usage needs to be mentioned in bullet points. 			
            Remember not to claim any solution for health issues.",
            'instructions' => '
            Bullet point must be of atleast 80 characters.
            Maintain a consistent order.
            If your first bullet point of your first product is material, keep that same order for all your products.
            Reiterate important information from the title and description
            Write all numbers as numerals

            Do Not Use hyphens, symbols, periods, or exclamation points just separate it with \n.
            be as specific as possible with product features and attributes

            Use the user provided data to generate atleast 5 bullet points.

            example response : ```bullet1 \n bullet2 \n bullet3 \n bullet4 \n bullet5 \n .... and so on.```` ',
            'additionals' => []
        ],
        // 'pet' => [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'toys' =>  [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'sports'=> [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'automotive'=> [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'electronics'=> [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'industrial'=> [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // 'office'=> [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "food" =>  [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "eyewear" =>  [
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "jewellery"=>[ 
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "home"=>[ 
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "watch"=>[ 
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "entertainment"=>[ 
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
        // "books"=>[ 
        //     'format' => "",
        //         'instructions' => '',
        //         'additionals' => []
        // ],
    ];
}
