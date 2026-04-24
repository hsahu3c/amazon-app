<?php

namespace App\Connector\Components\AI\Prompts\Instructions\Amazon;

class TitleData
{
    public function getTitleInstruction($key)
    {
        return $this->titles[$key] ?? [
            'format' => [
                'parent' => "<Brand> + <target audience> + <product name> | <keywords> +",
                'child' => "<Brand> + <target audience> + <product name> | <keywords> | <color> |<size>"
            ],

            'instructions' => "
             steps to create title:
            1. Extract Brand name, product name, find target audience from ```user provided json``` and add it as: 'brand target audience product name'.
            2. If you don't have info of target audience add it by yourself.
            3. Extract all the Keywords and append it as : '<brand target audience product name> | keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
            4. If <color> and <size> is mentioned in format extract it from ```user provided json``` and append it to title.
             ",
            'additionals' => [],
            'response' => [
                'parent' => "Nike Women oz flippers | perfect for your gym needs | light weight | abc | def | ghi | jkl ",
                'child' => "Nike Women oz flippers | perfect for your gym needs | light weight | affordable | abc | def | ghi | jkl | color blue | Size M"
            ],
            'user_input' => '{
            title : "nike black shoes oz flippers",
            description : "affordable light weight black shoes for women by Nike to fulfill your gym requirements",
            variant_attribute : "{size:"M",color:"black"}", 
            keywords : ["abc, def, ghi, jkl"]
                }',

        ];
    }

    public $titles = [
        'default' => [
            'format' => [
                'parent' => "<Brand> + <target audience> + <product name> | <keywords> +",
                'child' => "<Brand> + <target audience> + <product name> | <keywords> | <color> |<size>"
            ],

            'instructions' => "
             steps to create title:
            1. Extract Brand name, product name, find target audience from ```user provided json``` and add it as: 'brand target audience product name'.
            2. If you don't have info of target audience add it by yourself.
            3. Extract all the Keywords and append it as : '<brand target audience product name> | keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
            4. If <color> and <size> is mentioned in format extract it from ```user provided json``` and append it to title.
             ",
            'additionals' => [],
            'response' => [
                'parent' => "Nike Women oz flippers | perfect for your gym needs | light weight | abc | def | ghi | jkl ",
                'child' => "Nike Women oz flippers | perfect for your gym needs | light weight | affordable | abc | def | ghi | jkl | color blue | Size M"
            ],
            'user_input' => '{
            title : "nike black shoes oz flippers",
            description : "affordable light weight black shoes for women by Nike to fulfill your gym requirements",
            variant_attribute : "{size:"M",color:"black"}", 
            keywords : ["abc, def, ghi, jkl"]
                }',

        ],
        'clothing' => [
            'format' => [
                'parent' => "<Brand> + <target audience> + <product name> | <keywords> +",
                'child' => "<Brand> + <target audience> + <product name> | <keywords> | <color> |<size>"
            ],

            'instructions' => "
             steps to create title:
            1. Extract Brand name, product name, find target audience from ```user provided json``` and add it as: 'brand target audience product name'.
            2. If you don't have info of target audience add it by yourself.
            3. Extract all the Keywords and append it as : '<brand target audience product name> | keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
            4. If <color> and <size> is mentioned in format extract it from ```user provided json``` and append it to title.
             ",
            'additionals' => [],
            'response' => [
                'parent' => "Nike Women oz flippers | perfect for your gym needs | light weight | abc | def | ghi | jkl ",
                'child' => "Nike Women oz flippers | perfect for your gym needs | light weight | affordable | abc | def | ghi | jkl | color blue | Size M"
            ],
            'user_input' => '{
            title : "nike black shoes oz flippers",
            description : "affordable light weight black shoes for women by Nike to fulfill your gym requirements",
            variant_attribute : "{size:"M",color:"black"}", 
            keywords : ["abc, def, ghi, jkl"]
                }',

        ],
        'beauty' => [
            'format' => [
                'parent' => "<Brand>+ <target audience> + <Product Name> | <Product Usage> | <Specification> | <keywords>",
                'child' => "<Brand> + <target audience>+ <Product Name> | <Product Usage> | <Specification> | <keywords> | <Item Weight> | <Pack Size>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name, find target audience from ```user provided json``` and add it as: 'brand target audience product name'.
           2. Extract : product use and specifications from ```user provided json``` and add it as: 'brand target audience product name | usage | specification'
           3. Extract all the Keywords and append it as : ''brand target audience product name | usage | specification | keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <Item weight> and <Pack Size> is mentioned in format extract it from ```user provided json``` and append it to title.
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Swiss Beauty for Women Bold Matte Lipliner Pencil Set | perfect glowing lips | matte lipliner | water-resistant | lip liner |all shades |super-stay | long-wearing | matte finish | moisturizing ",
                'child' => "Swiss Beauty for Women Bold Matte Lipliner Pencil Set | perfect glowing lips | matte lipliner | water-resistant | lip liner |all shades |super-stay | long-wearing | matte finish | moisturizing | 10 gm | Pack of 10 "
            ],
            'user_input' => '{
                title : "Swiss Beauty Bold Matte Lipliner Pencil Set",
                description : "for Women perfect glowing lips,Water-Resistant: No more need to worry about any splash. With this water-proof lip-liner set, your lip look will never be runny. Lip Liner In All Shades: Swiss Beauty lip liner set pencils come in a variety of dashing shades from peach to rusty brown and traffic stopping red to line and define your lips. Lip Liner Pencil is also available in 12 more shades, you can choose your favourite shade. Super-Stay: Lip Pencil goes through all adventures and events without worrying about the touch-ups and will stay all day long with a smudge-proof effect. Long Wearing Lip Liner Pencil: This buttery soft, long wearing lip pencil formula goes on easily and resists bleeding. Matte Finish: Super Matte Lip Liner gives you a fantastic matte finish that will stay all day long. The matte lip liner pencil gives you the smooth texture and don’t let your lips dry. Moisturizing: This Lip Liner Set gives a moisturizing effect that will nourish and make your lips soft and plump.",,
                variant_attribute : "{
                    "brand": "swiss beauty",
                    "size":"Pack of 10",
                    "weight":"10gm"
                }", 
                keywords : ["matte lipliner, water-resistant, lip liner,all shades, super-stay, long-wearing, matte finish, moisturizing"]
            }',
        ],
        'toys' => [
            'format' => [
                'parent' => "<Brand> + <Sub Brand name> + <Product Title> | <keywords>",
                'child' => "<Brand> + <Sub Brand name> + <Product Title> | <keywords> | <Color> | <Model No.> | <Quantity>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name,Sub Brand name, product name from ```user provided json``` and add it as: 'brand sub_brand_name product name'.
           2. Extract all the Keywords and append it as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <model no> and <quantity> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | model no | quantity' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Cable World Plastic Battery Operated Converting Car |Robot to Car | Automatic | Robot Toy| with Light and Sound |for Kids |Indoor and Outdoor ",
                'child' => "Cable World Plastic Battery Operated Converting Car |Robot to Car | Automatic | Robot Toy| with Light and Sound |for Kids |Indoor and Outdoor |color  blue | model xy13| pack of 1"
            ],
            'user_input' => '{
                title : "Cable World Plastic Battery Operated Converting Car",
                description : "Cable World Robot Races Car Toy (Battery Operated) 2 in 1 Transform Car Toy with 4D Light Bright Lights and Music.Cable World
                Cable World Made of Non-Toxic Material(Product color may very as per stock availablity)Cable World, model Xy13",
                variant_attribute : "{
                    "color": "blue",
                    "quantity":"1",
                }", 
                keywords : ["Robot to Car", "Automatic","Robot Toy", "with Light and Sound", "for Kids", "Indoor and Outdoor" ]
            }',
        ],
        'sports' => [
            'format' => [
                'parent' => "<Brand> +<product name> +<Product Details> | <keywords> ",
                'child' => "<Brand> +<product name> +<Product Details> | <keywords> |<color> | <size> + <material type>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "MSB Sports Popular Willow Cricket Bat for Tennis Ball for Boys and Girls | willow bat |size 5| natural| sports equipment| popular| cover",
                'child' => "MSB Sports Popular Willow Cricket Bat for Tennis Ball | willow bat |size 5| natural| sports equipment| popular| cover| color blue | material Natural(ST-5-Popular) | size 5"
            ],
            'user_input' => '{
                title : "MSB Sports Popular Willow Cricket Bat for Tennis Ball",
                description : "POPULAR WILLOW CRICKET BAT MEANT FOR PLAYING BY ONLY 8+ Years BOYS and GIRLS. Used to hit a Tennis ball, this cricket bat is expertly crafted using Popular willow to provide long-lasting performance on the pitch. TENNIS BALL CRICKET BAT.",
                variant_attribute : "{
                    "color": "blue",
                    "material:"Natural(ST-5-Popular)",
                   "size":5
                }", 
                keywords : [ "willow bat", "size 5", "natural", "sports equipment", "popular", "cover", "boys", "girls" ]
            }',
        ],
        'automotive' => [
            'format' => [
                'parent' => "<Brand> + <Model] + <Product name> | <keywords>",
                'child' => "<Brand> + <Model] + <Product name> | <keywords> | <Size> | <Color>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality",
                'child' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality | size 30cm | color red"
            ],
            'user_input' => '{
                title : " One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit",
                description : "hand operated air/vacuum pump is easy to operate. Each squeeze of the pump delivers approximately 15ml, and can be used to create vacuum of 15mm Hg, or air pressure of about 2 atm (standard atmosphere). Pump features a gauge along with a removable cap and elastic valve to provide quick vacuum release without dismantling the pump from the line.",
                variant_attribute : "{
                    "brand": "Elecopto",
                    "color:"red",
                   "size":"30 cm",
                   model:"x"
                }", 
                keywords : [ automotive hand vacuum pump, brake bleeder kit,  hand operated, air/vacuum pump, easy to operate, gauge, quick vacuum release, high quality, professional ]
            }',

        ],

        'eyewear' => [
            'format' => [

                'parent' => "<Brand>  + <gender> +<Product name> +<item shape> + <polarized or not> | <sunglasses or glasses or readers> |<keywords>",
                'child' => "<Brand>  + <gender> +<Product name> +<item shape> + <polarized or not> | <sunglasses or glasses or readers> |<keywords> |<strength>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, gender, product name, item shape from ```user provided json``` and add it as: '<Brand>  <gender> <Product name> <item shape>'.
           2. Try to find the lense is polarized or not and also its typr. If it is polarized add: <Brand> <gender> <Product name> <item shape>  Polarised <sunglasses or glasses or readers>
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Vincent Chase By Lenskart | Black Grey Full Rim Rectangular | sunglasses | aerodynamics sunglasses | branded sunglasses | latest sunglasses | stylish sunglasses | 100% UV protected sunglasses",
                'child' =>
                "Vincent Chase By Lenskart | Black Grey Full Rim Rectangular | sunglasses | aerodynamics sunglasses | branded sunglasses | latest sunglasses | stylish sunglasses | 100% UV protected sunglasses | hard",
            ],
            'user_input' => '{
                title : "Vincent Chase By Lenskart",
                description : "<div>\n    <h2>Vincent Chase By Lenskart | Black Grey Full Rim Rectangular Sunglasses | 100% UV Protected | Men & Women | Medium | VC S15738</h2>\n    <p>Introducing the Vincent Chase Sunglasses by Lenskart, a perfect blend of style and functionality. These sunglasses feature a sleek black grey full rim rectangular frame that adds a touch of sophistication to any outfit. </p>\n    <p>The aerodynamics design of these sunglasses ensures a comfortable fit and reduces glare, making them ideal for outdoor activities and driving. </p>\n    <p>With 100% UV protection, these sunglasses shield your eyes from harmful sun rays, keeping them safe and healthy. </p>\n    <p>Designed for both men and women, these sunglasses are suitable for medium-sized faces. They are lightweight and durable, providing long-lasting comfort and style. </p>\n    <p>The Vincent Chase Sunglasses come with the brand guarantee of Lenskart, a trusted name in eyewear. </p>\n    <p>Elevate your style with these branded, latest, and stylish sunglasses. Order your pair of Vincent Chase Sunglasses today and step out in confidence. </p>\n</div>",
                variant_attribute : "{
                    "brand": "Vincent Chase by lenskart",
                    "color:"black grey",
                    "stength:"hard"
                }", 
                keywords : [aerodynamics sunglasses, branded sunglasses, latest sunglasses, stylish sunglasses, 100% UV protected sunglasses ]
            }',

        ],
        'jewellery' => [
            'format' => [

                'parent' => "<Brand> + <collection>  + <target_audience> +<Product name> +<product information> + <product type> |<keywords>",
                'child' => "<Brand> + <collection>  + <target_audience> +<Product name> +<product information> + <product type> |<keywords> | <length> | <color> |size"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :<Brand> + <collection>  + <target_audience> +<Product name> +<product information> + <product type> from ```user provided json``` and add it as: '<Brand> + <collection>  + <target_audience> +<Product name> +<product information> + <product type>'.
           2. Extract all the Keywords and append it as : '<Brand> + <collection>  + <target_audience> +<Product name> +<product information> + <product type> |<keywords>'
           3.  If <length> | <color> |size is mentioned in format extract it from ```user provided json``` and append it to title.
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Fashion Frill for Men and Women Hug Silver Rings | Made from high-quality stainless steel| silver rings| adjustable ring | women jewelry | couple ring | gift for sister | fashion jewelry | stainless steel ring | men jewelry | jewelry combo pack | Valentine gift",
                'child' =>
                "Fashion Frill for Men and Women Hug Silver Rings | Made from high-quality stainless steel| silver rings| adjustable ring | women jewelry | couple ring | gift for sister | fashion jewelry | stainless steel ring | men jewelry | jewelry combo pack | Valentine gift | silver | pack of 3",
            ],
            'user_input' => '{
                title : "Exclusive Hug Silver Rings",
                description : "<div><h2>Fashion Frill Exclusive Hug Silver Rings For Women</h2></div>\n\n<div>\n<p>Introducing the Fashion Frill Exclusive Hug Silver Rings For Women, a stunning and elegant addition to your jewelry collection. Made from high-quality stainless steel, these silver hug adjustable finger rings are designed to provide a comfortable and perfect fit for women of all sizes.</p>\n</div>\n\n<div>\n<p>Our extensive collection consists of alluring pieces of jewelry like earrings, rings, pendant sets, necklaces, anklets, mangalsutra, bracelets, and chains. These exquisite pieces are perfect to be worn on various occasions such as parties, weddings, engagements, work, office, or during the festive season.</p>\n</div>\n\n<div>\n<p>Each piece of jewelry from Fashion Frill is crafted with a sparkling finish and the best craftsmanship, showcasing our commitment to quality and style. Our brand is renowned for its classic and playful designs, making it the ideal choice for those who love to express their unique sense of fashion.</p>\n</div>\n\n<div>\n<p>Whether you are looking for a gift for your sister, a special someone, or a couple, our Fashion Frill Exclusive Hug Silver Rings are the perfect choice. The elegant and timeless design adds the perfect touch of sparkle to any outfit, making it a versatile accessory for any occasion.</p>\n</div>\n\n<div>\n<p>At Fashion Frill, we pride ourselves on creating jewelry that combines contemporary and traditional elements, as well as stylish and trendy designs. Our exhaustive assortment consists of alluring pieces of jewelry like earrings, necklaces, bangles, bracelets, mangalsutra, pendants, and more. We have accessories to accompany all types of attires, suitable for different occasions.</p>\n</div>\n\n<div>\n<p>Express your love and style with our divine jewelry collection. Order your Fashion Frill Exclusive Hug Silver Rings For Women today and experience the perfect blend of elegance and sophistication.</p>\n</div>",
                variant_attribute : "{color:silver,brand:Fashion Frill,model:ritick,size:pack of 3}", 
                keywords : [silver rings, adjustable ring, women jewelry, couple ring, gift for sister, fashion jewelry, stainless steel ring, men jewelry, jewelry combo pack, Valentine gift ]
            }',

        ],
        'food' => [
            'format' => [
                'parent' => "<Brand>  <Product name> +<Flavor/Color/Special Features> | <keywords>",
                'child' => "<Brand> + <Product name> + <Flavor/Color/Special Features> | <keywords> | <Size> | <Quantity> | <color>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: '<Brand> + <Product name> + <Flavor/Color/Special Features>'.
           2. Extract all the Keywords and append it as : '<Brand> + <Product name> + <Flavor/Color/Special Features>| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <quantity> is mentioned in format extract it from ```user provided json``` and append it to title .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Slurrp Farm Cereal Powered with organic super grain ragi mildly sweetened with no added sugar |cereal | Ragi | rice | strawberry | milk |instant food | healthy food | date powder",
                'child' =>
                "Slurrp Farm Cereal Powered with organic super grain ragi mildly sweetened with no added sugar |cereal | Ragi | rice | strawberry | milk |instant food | healthy food | date powder | pack of 1 | 200gm | brown",
            ],
            'user_input' => '{
                title : "Slurrp Farm Cereal",
                description : "Powered with organic super grain ragi. Ragi has 10x the calcium of wheat and rice. Great for growing bones.",
                variant_attribute : "{
                    "brand": "Slurrp Farm",
                    "color:"brown",
                   "size":"pack of 1",
                   "quantity:"200gm"
                }", 
                keywords : [ cereal, Ragi, rice, strawberry, milk, instant food, healthy food, no added sugar, mildly sweetened, date powder ]
            }',

        ],
        'industrial' => [
            'format' => [
                'parent' => "<Brand> + <Model> + <Product name> | <keywords>",
                'child' => "<Brand> + <Model> + <Product name> | <keywords> | <Size> | <Color>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality",
                'child' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality | size 30cm | color red"
            ],
            'user_input' => '{
                title : " One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit",
                description : "hand operated air/vacuum pump is easy to operate. Each squeeze of the pump delivers approximately 15ml, and can be used to create vacuum of 15mm Hg, or air pressure of about 2 atm (standard atmosphere). Pump features a gauge along with a removable cap and elastic valve to provide quick vacuum release without dismantling the pump from the line.",
                variant_attribute : "{
                    "brand": "Elecopto",
                    "color:"red",
                   "size":"30 cm",
                   model:"x"
                }", 
                keywords : [ automotive hand vacuum pump, brake bleeder kit,  hand operated, air/vacuum pump, easy to operate, gauge, quick vacuum release, high quality, professional ]
            }',

        ],
        'electronics' => [
            'format' => [
                'parent' => "<Brand> + <Model> + <Product name> | <keywords>",
                'child' => "<Brand> + <Model> + <Product name> | <keywords> | <Size> | <Color>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality",
                'child' =>
                "Elecopto One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit model X |automotive hand vacuum pump | brake bleeder kit | hand operated |air/vacuum pump | easy to operate | gauge | quick vacuum release | high quality | size 30cm | color red"
            ],
            'user_input' => '{
                title : " One man Automotive Hand Vacuum Pump Test and Brake Bleeder Kit",
                description : "hand operated air/vacuum pump is easy to operate. Each squeeze of the pump delivers approximately 15ml, and can be used to create vacuum of 15mm Hg, or air pressure of about 2 atm (standard atmosphere). Pump features a gauge along with a removable cap and elastic valve to provide quick vacuum release without dismantling the pump from the line.",
                variant_attribute : "{
                    "brand": "Elecopto",
                    "color:"red",
                   "size":"30 cm",
                   model:"x"
                }", 
                keywords : [ automotive hand vacuum pump, brake bleeder kit,  hand operated, air/vacuum pump, easy to operate, gauge, quick vacuum release, high quality, professional ]
            }',

        ],
        'pet' => [
            'format' => [
                'parent' => "<Brand>  <Product name> +<Flavor/Color/Special Features> | <keywords>",
                'child' => "<Brand> + <Product name> + <Flavor/Color/Special Features> | <keywords> | <Size> | <Quantity> | <color>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: '<Brand> + <Product name> + <Flavor/Color/Special Features>'.
           2. Extract all the Keywords and append it as : '<Brand> + <Product name> + <Flavor/Color/Special Features>| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <quantity> is mentioned in format extract it from ```user provided json``` and append it to title .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Slurrp Farm Cereal Powered with organic super grain ragi mildly sweetened with no added sugar |cereal | Ragi | rice | strawberry | milk |instant food | healthy food | date powder",
                'child' =>
                "Slurrp Farm Cereal Powered with organic super grain ragi mildly sweetened with no added sugar |cereal | Ragi | rice | strawberry | milk |instant food | healthy food | date powder | pack of 1 | 200gm | brown",
            ],
            'user_input' => '{
                title : "Slurrp Farm Cereal",
                description : "Powered with organic super grain ragi. Ragi has 10x the calcium of wheat and rice. Great for growing bones.",
                variant_attribute : "{
                    "brand": "Slurrp Farm",
                    "color:"brown",
                   "size":"pack of 1",
                   "quantity:"200gm"
                }", 
                keywords : [ cereal, Ragi, rice, strawberry, milk, instant food, healthy food, no added sugar, mildly sweetened, date powder ]
            }',

        ],
        'office' => [
            'format' => [
                'parent' => "<Brand>+<series name> +<product name> +with+<Product Details> | <keywords> ",
                'child' => "<Brand>+<series name> +<product name> +with+<Product Details> | <keywords> |<color> | <size> + <material type>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "MSB Sports Popular Willow Cricket Bat for Tennis Ball for Boys and Girls | willow bat |size 5| natural| sports equipment| popular| cover",
                'child' => "MSB Sports Popular Willow Cricket Bat for Tennis Ball | willow bat |size 5| natural| sports equipment| popular| cover| color blue | material Natural(ST-5-Popular) | size 5"
            ],
            'user_input' => '{
                title : "MSB Sports Popular Willow Cricket Bat for Tennis Ball",
                description : "POPULAR WILLOW CRICKET BAT MEANT FOR PLAYING BY ONLY 8+ Years BOYS and GIRLS. Used to hit a Tennis ball, this cricket bat is expertly crafted using Popular willow to provide long-lasting performance on the pitch. TENNIS BALL CRICKET BAT.",
                variant_attribute : "{
                    "color": "blue",
                    "material:"Natural(ST-5-Popular)",
                   "size":5
                }", 
                keywords : [ "willow bat", "size 5", "natural", "sports equipment", "popular", "cover", "boys", "girls" ]
            }',
        ],

        'home' => [
            'format' => [
                'parent' => "<Brand> + <Product Name/Material> + <Product Type> + <Model Number if available> | <keywords> ",
                'child' => "<Brand> + <Product Name/Material> + <Product Type> + <Model Number if available> | <keywords> |<size> | <quantity> | <color> "
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand name, product name and product details from ```user provided json``` and add it as: 'brand product_name product_details'.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           4. If <color>, <size> and <material type> is mentioned in format extract it from ```user provided json``` and append it to title as : 'brand sub_brand_name product name| keyword1 | keyword2 | ..... |keywordN |color | size | material type' .
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "nantan Decorative Buddha Showpiece |Figurine for Home Decor | decorative Buddha showpiece | home decor | living room decor | bedroom decor | office decor | cabinets decor | Indian artisans",
                'child' => "nantan Decorative Buddha Showpiece |Figurine for Home Decor | decorative Buddha showpiece | home decor | living room decor | bedroom decor | office decor | cabinets decor | Indian artisans | size 5 | pack of 34 | blue",
            ],
            'user_input' => '{
                title : "nantan Decorative Buddha Showpiece |Figurine for Home Decor | Showpiece Living Room, Bedroom, Office Desk, Cabinets",
                description : "perfect Figurine for Home Decor",
                variant_attribute : "{
                    "color": "blue",
                    "quantity:"34",
                   "size":5
                }", 
                keywords : [ decorative Buddha showpiece, home decor, living room decor, bedroom decor, office decor, cabinets decor, Indian artisans ]
            }',
        ],
        'watch' => [
            'format' => [
                'parent' => "<brand>  + <display_type> + <watch_movement_type>+<name> + <target_audience> + Watch with+ <band_material_type> + bracelet + <part_number> + <keywords>",
                'child' => "<brand> +<display_type> + <watch_movement_type>+<name> + <target_audience> + Watch with+ <band_material_type> + “bracelet” + <part_number> + <keywords>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :Brand target_audience,display type , name , watch movement type details from ```user provided json``` and append it on title.
           2. Extract all the Keywords and append it as : 'brand product_name product_details| keyword1 | keyword2 | ..... |keywordN'  add all the provided keywords.
           3. Extract all the data given in format and append it to title.
           4. It is mandator to include dial type and all the words given in format watch with and bracelet.
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Timex Analog Brown Dial  Watch-TW000U936 Men's Watch with Brown Leather bracelet |brown dial | tw000u936 |  timex analog watch | brown mens watch | 45mm dial | 2 year warranty",
                'child' =>  "Timex Analog Brown Dial  Watch-TW000U936 Men's Watch with Brown Leather bracelet and 4mm dial |brown dial | tw000u936 |  timex analog watch | brown mens watch | 45mm dial | 2 year warranty",
            ],
            'user_input' => '{
                title : "Timex Analog Brown Dial Mens Watch-TW000U936",
                description : "Stay fashionable with TIMEX Analog Brown Men Watch TW000U936 from TIMEX This analog watch has round Brown dial with a dial diatemeter of 45 millimeters.This watch has 2 year manufacturer warranty.It includes brown leather strap",
                variant_attribute : "{
                    "material_type":"leather",
                }", 
                keywords : [  brown dial, tw000u936, timex analog watch, brown mens watch, 45mm dial, 2 year warranty ]
            }',
        ],

        'entertainment' => [
            'format' => [
                'parent' => "<Year> + <Artist Name> + <Autographed> + <Film Title/Album Title/ name> + <Item Type> | <keywords>",
                'child' => "<Year> + <Artist Name> + <Autographed> + <Film Title/Album Title> + <Item Type> | <keywords>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :year artist, autographed , title, album or name type details from ```user provided json``` and append it on title.
           2. Extract all the Keywords and append it to title.
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "Tallenge - Bruce Springsteen - Live at The Roxy in Hollywood 1975 - Rock Music Vintage Concert Poster - Small Poster Paper (12 x 17 inches) Rock Music | Vintage Concert Poster | Small Poster Paper | 12 x 17 inches | solo artist | E Street Band | Born to Run",
                'child' =>  "Tallenge - Bruce Springsteen - Live at The Roxy in Hollywood 1975 - Rock Music Vintage Concert Poster - Small Poster Paper (12 x 17 inches) Rock Music | Vintage Concert Poster | Small Poster Paper | 12 x 17 inches | solo artist | E Street Band | Born to Run",
            ],
            'user_input' => '{
                title : "Hollywood 1975 - Rock Music Vintage Concert Poster",
                description : "
                12*17 inches small paper poster of Rock Music Vintage Concert, 1975
                Tallenge - Bruce Springsteen -  Joseph Springsteen (born September 23, 1949) is an American singer, songwriter, and musician who is both a solo artist and the leader of the E Street Band. Originally from the Jersey Shore, he received critical acclaim for his early 1970s albums and attained worldwide fame upon the release of Born to Run in 1975. During a career that has spanned five decades, Springsteen has become known for his poetic and socially conscious lyrics and lengthy, energetic stage performances. He has been given the nickname ",
                variant_attribute : "{
                    "material_type":"paper",
                    "type":"poster",
                    "size":12*17 inches
                }", 
                keywords : [ Rock Music, Vintage Concert Poster, Small Poster Paper, 12 x 17 inches, solo artist, E Street Band, Born to Run ]
            }',
        ],
        'books' => [
            'format' => [
                'parent' => "<name> + <author> + <year> + <publication> + <type> |<keywords>",
                'child' => "<name> + <author> + <year> + <publication> + <type> |<keywords>"
            ],

            'instructions' => "
            steps to create title:
           1. Extract :name, author, year , publication, type details from ```user provided json``` and append it on title.
           2. Extract all the Keywords and append it to title.
            ",
            'additionals' => [],
            'response' => [
                'parent' =>
                "BlackBook of English Vocabulary March 2023 by Nikhil Gupta Paperback – 22 February 2023 | trans publication | English Vocabulary | BlackBook | March 2023 |Nikhil Gupta | Paperback | books",
                'child' =>  "BlackBook of English Vocabulary March 2023 by Nikhil Gupta Paperback – 22 February 2023 | trans publication | English Vocabulary | BlackBook | March 2023 |Nikhil Gupta | Paperback | books",
            ],
            'user_input' => '{
                title : "BlackBook of English Vocabulary",
                description : "BlackBook of English Vocabulary  by Nikhil Gupta, version 2023  is a Paperback – 22 February 2023 editon from trans publication",
                variant_attribute : "{
                    "material_type":"hard cover",
                    "type":"books",
                }", 
                keywords : [ English Vocabulary, BlackBook, March 2023, Nikhil Gupta, Paperback, books ]
            }',
        ],

    ];
}
