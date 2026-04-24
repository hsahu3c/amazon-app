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

namespace App\Shopifyhome\Components\Product\Bulk;

use App\Shopifyhome\Components\Core\Common;

class Utility extends Common
{

    public function splitIntoProgressBar($totalCount, $size, $totalWeightage = 100)
    {
        $individualWeight = ($totalWeightage / ceil($totalCount / $size));
        $data['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
        $data['cursor'] = 0;
        $data['total'] = $totalCount;
        $data['total_cursor'] = ceil($totalCount / $size);

        if($data['cursor'] == ($data['total_cursor'] - 1)) $data['delete_file_at_last'] = 1;

        return $data;
    }

    public function splitIntoProgressBarDynammic($totalProductCount, $size, $totalVariantCount, $totalWeightage = 100)
    {
        $individualWeight = ($totalWeightage / ceil($totalProductCount / $size));
        $data['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
        $data['cursor'] = 0;
        $data['total'] = $totalVariantCount;
        $data['total_cursor'] = ceil($totalProductCount / $size);

        if($data['cursor'] == ($data['total_cursor'] - 1)) $data['delete_file_at_last'] = 1;

        return $data;
    }


    public function testProductBulkDataInitiate()
    {
         return ['success' => true, 'data' =>
             ['id' => 'gid://shopify/BulkOperation/3998711948', 'status' => 'CREATED'], 'ip' => '127.0.0.1'];
    }

    public function testProductBulkDataStatuscheck()
    {
        return ['success' => true, 'data' =>
            ['id' => 'gid://shopify/BulkOperation/3999137932', 'status' => 'RUNNING', 'errorCode' => NULL, 'createdAt' => '2020-04-01T09:26:46Z', 'completedAt' => NULL, 'objectCount' => '0', 'fileSize' => NULL, 'url' => NULL, 'partialDataUrl' => NULL], 'ip' => '127.0.0.1'];
    }

    public function testProductBulkDataComplete()
    {
        return ['success' => true, 'data' =>
            ['id' => 'gid://shopify/BulkOperation/3997794444', 'status' => 'COMPLETED', 'errorCode' => NULL, 'createdAt' => '2020-04-01T06:51:36Z', 'completedAt' => '2020-04-01T06:53:25Z', 'objectCount' => '1910', 'fileSize' => '1528422', 'url' => 'https://storage.googleapis.com/shopify-tiers-assets-prod-us-east1/9vgkf4r1wdx1focqlf2cbq6y9ug4?GoogleAccessId=assets-us-prod%40shopify-tiers.iam.gserviceaccount.com&Expires=1586328805&Signature=SVofgeY4mtJXKZnotd%2FCFWy7J1bb6hynI3ge5oGW0sR360DAwccQO3%2FclFvn0OVWy0d2FKfrX3oCUQugkH%2BENp6c6ThAC58CjTmP4S%2BZUipHZBZOPA5hNdEb6YuA1nvcDNnaPCaPAC79bUexs%2FHoWbndBiUqXU04Trq8TFrpSID%2FSbhx6TS9xBOgQ9FC%2BkBvc9sfUa6KlA%2BCwIz2PGCu1jlFJAQ78AJZkb8bg%2BQoeatHVVQMJjlwMWMCLYF%2Fy08q6UeDfBOisQSokvS0pCNfD0QGTtNTKHer8KamJm3FjBKjeNmr9qSr6QQmzZbavptFTfgaVtFmaCp2YneAcsjPIw%3D%3D&response-content-disposition=attachment%3B+filename%3D%22bulk-3997794444.jsonl%22%3B+filename%2A%3DUTF-8%27%27bulk-3997794444.jsonl&response-content-type=application%2Fjsonl', 'partialDataUrl' => NULL], 'ip' => '127.0.0.1'];
    }

    public function testProductBulkCancel()
    {
        return ['success' => false, 'msg' => 'A bulk operation cannot be canceled when it is completed', 'error' =>
            ['field' => NULL, 'message' => 'A bulk operation cannot be canceled when it is completed'], 'ip' => '127.0.0.1'];
    }

    public function testProductBulkCount(){
        return ['success' => 1, 'data' => 437, 'ip' => '127.0.0.1'];
    }

    public function testProductBulkData()
    {
        return ['success' => true, 'data' =>
            [0 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-09T12:30:55Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / RED', 'position' => 1, 'sku' => 'P1-P1', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 147, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'RED', 'inventory_item_id' => '33928504705164', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504705164', 'updated_at' => NULL, 'available' => 147, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 1 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T18:03:10Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / BLUE', 'position' => 2, 'sku' => 'P1-P2', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 146, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'BLUE', 'inventory_item_id' => '33928504737932', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504737932', 'updated_at' => NULL, 'available' => 146, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 2 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-09T12:30:55Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / ORANGE', 'position' => 3, 'sku' => 'P1-P3', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 149, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'ORANGE', 'inventory_item_id' => '33928504770700', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504770700', 'updated_at' => NULL, 'available' => 149, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 3 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'M / RED', 'position' => 4, 'sku' => 'P1-P4', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'M', 'Color' => 'RED', 'inventory_item_id' => '33928504803468', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504803468', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 4 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'M / BLUE', 'position' => 5, 'sku' => 'P1-P5', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'M', 'Color' => 'BLUE', 'inventory_item_id' => '33928504836236', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504836236', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 5 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'M / ORANGE', 'position' => 6, 'sku' => 'P1-P6', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'M', 'Color' => 'ORANGE', 'inventory_item_id' => '33928504869004', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504869004', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 6 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'S / RED', 'position' => 7, 'sku' => 'P1-P7', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'S', 'Color' => 'RED', 'inventory_item_id' => '33928504901772', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504901772', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 7 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'S / BLUE', 'position' => 8, 'sku' => 'P1-P8', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'S', 'Color' => 'BLUE', 'inventory_item_id' => '33928504934540', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504934540', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 8 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'S / ORANGE', 'position' => 9, 'sku' => 'P1-P9', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'S', 'Color' => 'ORANGE', 'inventory_item_id' => '33928504967308', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928504967308', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 9 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / RED', 'position' => 10, 'sku' => 'P1-P10', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'RED', 'inventory_item_id' => '33928505032844', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928505032844', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 10 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / BLUE', 'position' => 11, 'sku' => 'P1-P11', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'BLUE', 'inventory_item_id' => '33928505065612', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928505065612', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 11 =>
                ['type' => 'variation', 'group_id' => '4599471767692', 'container_id' => '4599471767692', 'name' => 'Product One test new', 'brand' => 'test_rahul_facebook', 'product_type' => '', 'description_without_html' => 'PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT PRODUCT', 'description' => '<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<p> </p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span><span>PRODUCT</span></span></p>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<p><span>PRODUCT</span></p>', 'handle' => 'product-one', 'tags' => '', 'templateSuffix' => '', 'main_image' => 'https://cdn.shopify.com/s/files/1/0342/6880/7308/products/Untitled-1.jpg?v=1582728007', 'publishedAt' => '2020-02-26T14:40:06Z', 'createdAt' => '2020-02-26T14:40:08Z', 'updatedAt' => '2020-03-08T11:27:46Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / ORANGE', 'position' => 12, 'sku' => 'P1-P12', 'offer_price' => '0.25', 'price' => '1200.00', 'quantity' => 152, 'weight' => 0, 'weightUnit' => 'POUNDS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'ORANGE', 'inventory_item_id' => '33928505098380', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33928505098380', 'updated_at' => NULL, 'available' => 152, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 12 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T14:24:20Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / Orange', 'position' => 1, 'sku' => 'sdfsdfsd-1', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 4800, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => 'sdfsdfsdf', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'Orange', 'inventory_item_id' => '33980723658892', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723658892', 'updated_at' => NULL, 'available' => 300, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723658892', 'updated_at' => NULL, 'available' => 4500, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 13 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T13:53:25Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / Pink', 'position' => 2, 'sku' => 'sdfsdfsd-2', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 207, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'Pink', 'inventory_item_id' => '33980723691660', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723691660', 'updated_at' => NULL, 'available' => 200, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723691660', 'updated_at' => NULL, 'available' => 7, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 14 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T13:53:25Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'XL / Blue', 'position' => 3, 'sku' => 'sdfsdfsd-3', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 4512, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'XL', 'Color' => 'Blue', 'inventory_item_id' => '33980723724428', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723724428', 'updated_at' => NULL, 'available' => 4500, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723724428', 'updated_at' => NULL, 'available' => 12, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 15 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T13:39:08Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / Orange', 'position' => 4, 'sku' => 'sdfsdfsd-4', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 3, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'Orange', 'inventory_item_id' => '33980723757196', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723757196', 'updated_at' => NULL, 'available' => 3, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723757196', 'updated_at' => NULL, 'available' => 0, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 16 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T13:39:08Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / Pink', 'position' => 5, 'sku' => 'sdfsdfsd-5', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 47, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'Pink', 'inventory_item_id' => '33980723789964', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723789964', 'updated_at' => NULL, 'available' => 45, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723789964', 'updated_at' => NULL, 'available' => 2, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]], 17 =>
                ['type' => 'variation', 'group_id' => '4608766771340', 'container_id' => '4608766771340', 'name' => 'test 002', 'brand' => 'test_rahul_facebook_local', 'product_type' => '', 'description_without_html' => 'Product Data 002 Product Data 003', 'description' => '<p>Product Data 002</p>
<p>Product Data 003</p>', 'handle' => 'test-002', 'tags' => '', 'templateSuffix' => '', 'main_image' => NULL, 'publishedAt' => '2020-02-29T12:42:03Z', 'createdAt' => '2020-02-29T13:39:08Z', 'updatedAt' => '2020-02-29T13:39:08Z', 'variant_attributes' =>
                    [0 => 'Size', 1 => 'Color'], 'variant_title' => 'L / Blue', 'position' => 6, 'sku' => 'sdfsdfsd-6', 'offer_price' => '45.00', 'price' => '75.00', 'quantity' => 6, 'weight' => 0, 'weightUnit' => 'KILOGRAMS', 'barcode' => '', 'inventoryPolicy' => 'DENY', 'taxable' => true, 'fulfillment_service' => 'manual', 'variant_image' => NULL, 'Size' => 'L', 'Color' => 'Blue', 'inventory_item_id' => '33980723822732', 'inventory_tracked' => true, 'requires_shipping' => true, 'inventory_levels' =>
                    [0 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027873932?inventory_item_id=33980723822732', 'updated_at' => NULL, 'available' => 4, 'location' =>
                            ['id' => '41082814604', 'address' =>
                                ['address1' => '67-B Nanak Nagar Thakurganj Lucknow', 'address2' => '', 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => NULL, 'longitude' => NULL, 'phone' => '+917835053404', 'province' => 'Andaman and Nicobar', 'provinceCode' => 'AN', 'zip' => '226003']]], 1 =>
                        ['admin_graphql_api_id' => 'gid://shopify/InventoryLevel/75027677324?inventory_item_id=33980723822732', 'updated_at' => NULL, 'available' => 2, 'location' =>
                            ['id' => '41082617996', 'address' =>
                                ['address1' => 'Thakurganj, Daulatganj, Lucknow, Uttar Pradesh, India', 'address2' => NULL, 'city' => 'Lucknow', 'country' => 'India', 'countryCode' => 'IN', 'latitude' => 26.8762367, 'longitude' => 80.89035229999999, 'phone' => '', 'province' => 'Uttar Pradesh', 'provinceCode' => 'UP', 'zip' => '226003']]]]]], 'cursors' =>
            ['next' => 'Mn4wfjQ1'], 'count' =>
            ['product' => 2, 'variant' => 18], 'ip' => '127.0.0.1'];
    }

}