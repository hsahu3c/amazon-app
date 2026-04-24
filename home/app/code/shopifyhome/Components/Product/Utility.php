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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;

class Utility extends Common
{
    public function shopNodeToExcludeAndFormatData($shopRawData)
    {
        $nodeToExclude = ['latitude', 'longitude', 'google_apps_domain', 'google_apps_login_enabled', 'eligible_for_payments', 'requires_extra_payments_agreement', 'force_ssl', 'cookie_consent_level', 'force_ssl', 'checkout_api_supported', 'setup_required', 'pre_launch_enabled'];
        foreach($nodeToExclude as $node){
            unset($shopRawData[$node]);
        }

        $shopRawData['id'] = (string)$shopRawData['id'];
        $shopRawData['price'] = floatval($shopRawData['price']);
        $shopRawData['offer_price'] = floatval($shopRawData['offer_price']);

        return $shopRawData;
    }

    /*
     * SImple function to covert microseconds to UNIX timestamp with microseconds
     */
    public function unixTimeInMicroSeconds()
    {
        [$usec, $sec] = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    public function DataForLog($logdata){
        $fields_arr=['type','id','name','container_id','variant_title','inventory_item_id','quantity'];
        foreach ($logdata['data'] as $k=>$product){
            $product_keys=array_keys($product);
            foreach ($product_keys as $key){
                if(!in_array($key,$fields_arr)){
                    unset($logdata['data'][$k][$key]);
                }
            }
        }

        return $logdata;
    }

    public function testShopifyProductData(){
        return [
            'success' => true,
            'data' => [
                [
                    'type' => 'simple',
                    'id' => (string)32153673203848,
                    'name' => '1 Year Extended Warranty ($19)-Price up to $100 Only',
                    'description' => '<p><strong>Protect Your Investment with JemJem\s Extended Warranty</strong></p>
<p>JemJem has you covered up to one year after your purchase, so you can use your device to the fullest knowing we have your back.</p>
<p><strong>This Extended Warranty Plan is only for purchases up to $100 products. Any purchases over $100(Before Shipping and Taxes) will NOT be covered with this plan. &nbsp;Please check plans that meets your purchases.</strong></p>
<p>Warranty Coverage Includes:</p>
<ul>
<li>Labor and Parts for all mechanical issues</li>
<li>In case item cannot be fixed, the customer will be issued a similar item for replacement.</li>
</ul>
<p><a href="https://www.jemjem.com/pages/warranty">Learn more about Warranty Coverage and Restrictions</a></p>',
                    'brand' => 'JemJem',
                    'product_type' => 'Warranty',
                    'created_at' => "2020-02-05T12:10:03-05:00",
                    'handle' => "1-year-extended-warranty-100",
                    'updated_at' => "2020-02-05T12:10:03-05:00",
                    'published_at' => "2020-02-05T12:10:01-05:00",
                    'template_suffix' => "gt",
                    'published_scope' => "web",
                    'tags' => "xwarranty",
                    'admin_graphql_api_id' => "gid://shopify/ProductVariant/32153673203848",
                    'main_image' => "https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Untitled-5-01.png?v=>1580922603",
                    'container_id' => (string)4534956589192,
                    'variant_title' => "Default Title",
                    'offer_price' => 19.00,
                    'sku' => "1YearWarranty-100",
                    'position' => 1,
                    'images' => [
                        'https://cdn.shopify.com/s/files/1/0321/4544/0904/products/bottles-1471071.jpg?v=1581764442',
                        'https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Black_Hole_crop.jpg?v=1581764834'
                    ],
                    'inventory_policy' => "deny",
                    'price' =>29.99,
                    'fulfillment_service' => "manual",
                    'inventory_management' => "shopify",
                    'taxable' => 1,
                    'barcode' => '',
                    'grams' => 0,
                    'weight' => 0,
                    'weight_unit' => 'lb',
                    'inventory_item_id' => (string)33832893644936,
                    'quantity' => 9799,
                    'old_inventory_quantity' => 9799,
                    'requires_shipping' => ''
                ],
                [
                    'type' => 'variation',
                    'id' => (string)32153327632520,
                    'name' => 'Apple iPad 2 - 9.7" (16GB, Black) - Bluetooth, iOS, WiFi, Tablet (Refurbished)',
                    'description' => '<img src="https://cdn.shopify.com/s/files/1/1936/3979/files/test-05.jpg?789"> <div>JemJem certified refurbished Apple iPad 2. Comes with 90 Day warranty by JemJem. With a lighter and thinner design, the iPad 2 fits even more comfortably in your hand and carries more power than the previous generation with the dual-core A5 chip. With features such as apps, games, and music, a LED-backlit display, and a rear and front facing camera so you can enjoy FaceTime video chat, this tablet is sure to keep you satisfied and entertained.
<ul>
<li>1GHz</li>
<li>1024 x 768 pixel resolution</li>
<li>802.11a/b/g/n WiFi</li>
<li>9.7" screen display 1.3 lbs</li>
<li>non OEM Charger Included</li>
<li>Model:&nbsp;MC769LL/A</li>
</ul>
</div>',
                    'brand' => 'Apple',
                    'product_type' => 'iPad 2',
                    'created_at' => "2020-02-05T12:10:03-05:00",
                    'handle' => "ipad-2-wifi-black-16gb-jj",
                    'updated_at' => "2020-02-05T12:10:03-05:00",
                    'published_at' => "2020-02-05T12:10:01-05:00",
                    'template_suffix' => "gt",
                    'published_scope' => "web",
                    'group_id' => (string)4534891413640,
                    'tags' => "xwarranty",
                    'admin_graphql_api_id' => "gid://shopify/ProductVariant/32153673203848",
                    'variant_attributes' => ['Cosmetic Condition'],
                    'main_image' => "https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Untitled-5-01.png?v=>1580922603",
                    'container_id' => (string)4534891413640,
                    'variant_title' => "Default Title",
                    'offer_price' => 19.00,
                    'sku' => "1YearWarranty-100",
                    'position' => 1,
                    'inventory_policy' => "deny",
                    'price' =>29.99,
                    'fulfillment_service' => "manual",
                    'inventory_management' => "shopify",
                    'taxable' => 1,
                    'barcode' => '',
                    'grams' => 0,
                    'weight' => 0,
                    'weight_unit' => 'lb',
                    'inventory_item_id' => (string)33832893644936,
                    'quantity' => 9799,
                    'old_inventory_quantity' => 9799,
                    'requires_shipping' => '',
                    'variant_image' => 'https://cdn.shopify.com/s/files/1/0321/4544/0904/products/ipad2-black_a1e28b92-661d-433a-ad32-c3c8d784b7c3.png?v=1580921043',
                    'images' => [
                        'https://cdn.shopify.com/s/files/1/0321/4544/0904/products/bottles-1471071.jpg?v=1581764442',
                        'https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Black_Hole_crop.jpg?v=1581764834'
                    ]
                ],
                [
                    'type' => 'variation',
                    'id' => (string)32153327665288,
                    'name' => 'Apple iPad 2 - 9.7" (16GB, Black) - Bluetooth, iOS, WiFi, Tablet (Refurbished)',
                    'description' => '<img src="https://cdn.shopify.com/s/files/1/1936/3979/files/test-05.jpg?789"> <div>JemJem certified refurbished Apple iPad 2. Comes with 90 Day warranty by JemJem. With a lighter and thinner design, the iPad 2 fits even more comfortably in your hand and carries more power than the previous generation with the dual-core A5 chip. With features such as apps, games, and music, a LED-backlit display, and a rear and front facing camera so you can enjoy FaceTime video chat, this tablet is sure to keep you satisfied and entertained.
<ul>
<li>1GHz</li>
<li>1024 x 768 pixel resolution</li>
<li>802.11a/b/g/n WiFi</li>
<li>9.7" screen display 1.3 lbs</li>
<li>non OEM Charger Included</li>
<li>Model:&nbsp;MC769LL/A</li>
</ul>
</div>',
                    'brand' => 'Apple',
                    'product_type' => 'iPad 2',
                    'created_at' => "2020-02-05T12:10:03-05:00",
                    'handle' => "ipad-2-wifi-black-16gb-jj",
                    'updated_at' => "2020-02-05T12:10:03-05:00",
                    'published_at' => "2020-02-05T12:10:01-05:00",
                    'template_suffix' => "gt",
                    'published_scope' => "web",
                    'tags' => "xwarranty",
                    'admin_graphql_api_id' => "gid://shopify/ProductVariant/32153673203848",
                    'variant_attributes' => ['Cosmetic Condition'],
                    'main_image' => "https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Untitled-5-01.png?v=>1580922603",
                    'container_id' => (string)4534891413640,
                    'group_id' => (string)4534891413640,
                    'variant_title' => "Default Title",
                    'offer_price' => 19.00,
                    'sku' => "1YearWarranty-100",
                    'position' => 1,
                    'inventory_policy' => "deny",
                    'price' =>29.99,
                    'fulfillment_service' => "manual",
                    'inventory_management' => "shopify",
                    'taxable' => 1,
                    'barcode' => '',
                    'grams' => 0,
                    'weight' => 0,
                    'weight_unit' => 'lb',
                    'inventory_item_id' => (string)33832893644936,
                    'quantity' => 9799,
                    'old_inventory_quantity' => 9799,
                    'requires_shipping' => '',
                ],
                [
                    'type' => 'variation',
                    'id' => (string)32153327665292,
                    'name' => 'Apple iPad 2 - 9.7" (16GB, Black) - Bluetooth, iOS, WiFi, Tablet (Refurbished)',
                    'description' => '<img src="https://cdn.shopify.com/s/files/1/1936/3979/files/test-05.jpg?789"> <div>JemJem certified refurbished Apple iPad 2. Comes with 90 Day warranty by JemJem. With a lighter and thinner design, the iPad 2 fits even more comfortably in your hand and carries more power than the previous generation with the dual-core A5 chip. With features such as apps, games, and music, a LED-backlit display, and a rear and front facing camera so you can enjoy FaceTime video chat, this tablet is sure to keep you satisfied and entertained.
<ul>
<li>1GHz</li>
<li>1024 x 768 pixel resolution</li>
<li>802.11a/b/g/n WiFi</li>
<li>9.7" screen display 1.3 lbs</li>
<li>non OEM Charger Included</li>
<li>Model:&nbsp;MC769LL/A</li>
</ul>
</div>',
                    'brand' => 'Apple',
                    'product_type' => 'iPad 2',
                    'created_at' => "2020-02-05T12:10:03-05:00",
                    'handle' => "ipad-2-wifi-black-16gb-jj",
                    'updated_at' => "2020-02-05T12:10:03-05:00",
                    'published_at' => "2020-02-05T12:10:01-05:00",
                    'template_suffix' => "gt",
                    'published_scope' => "web",
                    'tags' => "xwarranty",
                    'admin_graphql_api_id' => "gid://shopify/ProductVariant/32153673203848",
                    'variant_attributes' => ['Cosmetic Condition'],
                    'main_image' => "https://cdn.shopify.com/s/files/1/0321/4544/0904/products/Untitled-5-01.png?v=>1580922603",
                    'container_id' => (string)4534891413640,
                    'group_id' => (string)4534891413640,
                    'variant_title' => "Default Title",
                    'offer_price' => 19.00,
                    'sku' => "1YearWarranty-100",
                    'position' => 1,
                    'inventory_policy' => "deny",
                    'price' =>29.99,
                    'fulfillment_service' => "manual",
                    'inventory_management' => "shopify",
                    'taxable' => 1,
                    'barcode' => '',
                    'grams' => 0,
                    'weight' => 21,
                    'weight_unit' => 'lb',
                    'inventory_item_id' => (string)33832893644989,
                    'quantity' => 100,
                    'old_inventory_quantity' => 9799,
                    'requires_shipping' => '',
                ]
            ]
        ];
    }
}