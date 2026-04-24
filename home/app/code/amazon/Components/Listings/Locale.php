<?php

namespace App\Amazon\Components\Listings;

use App\Core\Components\Base as Base;

class Locale extends Base
{
    public const ASIN_EMPTY_ERRORED_MESSAGE = 'Product does not have a valid ASIN for offer creation. Please initiate lookup to fetch offer status.';

    public const OFFER_RESTRCTION_MESSAGE = 'You are not authorized to sell this product under the requested condition type.';

    public const CATEGORY_EMPTY_ERRORED_MESSAGE = 'Amazon Category is not selected. Click on Product to edit & select an Amazon Category to upload this Product.';

    public const SKU_EMPTY_ERRORED_MESSAGE = 'Sku is a required field, please fill sku and upload again.';

    public const PRICE_EMPTY_EMPTY_ERRORED_MESSAGE = 'Price is a required field, please fill price and upload again.';

    public const MINIMUM_PRICE_MORE_THAN_PRICE_ERRORED_MESSAGE = 'Minimum price should not be greater than Standard price.';

    public const SALE_PRICE_MORE_THAN_PRICE_ERRORED_MESSAGE = 'Sale price should not be greater than Standard price.';

    public const QUANTITY_EMPTY_ERRORED_MESSAGE = 'Quantity is a required field, please fill quantity and upload again.';

    public const BARCODE_EMPTY_ERRORED_MESSAGE = 'Barcode is a required field, please fill barcode and upload again.';

    public const BARCODE_TYPE_ERRORED_MESSAGE = 'Barcode must be of type EAN, GTIN, EAN-8, or UPC for product validation.';

    public const PARENT_OFFER_ERRORED_MESSAGE = 'Offers cannot be created for parent products.';

    public const DESCRIPTION_EXCEEDING_ERRORED_MESSAGE = 'Product Descriptions are exceeding the 2000 characters limit. HTML tags and spaces do count as characters.';

    public const NAME_EXCEEDING_ERRORED_MESSAGE = 'Product name is exceeding the 200 characters limit. Spaces and special characters do count as characters.';

    public const SKU_EXCEEDING_ERRORED_MESSAGE = 'SKU lengths are exceeding the 40 character limit.';

    public const PRICE_ZERO_ERRORED_MESSAGE = 'Price should be greater than 0.01, please update it from the shopify listing page.';

    public const BARCODE_INVALID_ERRORED_MESSAGE = 'Barcode is not valid, please provide a valid barcode and upload again.';

    public const CONDITION_TYPE_EMPTY_ERRORED_MESSAGE = '"condition_type" is required but not supplied.';

    public const IMAGE_EMPTY_ERRORED_MESSAGE = 'The selected action cannot be performed on this product because product does not have contains image.';

    public const PRODUCT_SETTING_DISABLED_ERRORED_MESSAGE = 'Product Syncing is Disabled. Please Enable it from the Settings and Try Again.';

    public const INVENTORY_SETTING_DISABLED_ERRORED_MESSAGE = 'Inventory Syncing is disabled. Please check the Settings and Try Again.';

    public const PRICE_SETTING_DISABLED_ERRORED_MESSAGE = 'Price Syncing is Disabled. Please Enable it from the Settings and Try Again.';

    public const IMAGE_SETTING_DISABLED_ERRORED_MESSAGE = 'Image Syncing is Disabled. Please Enable it from Settings and Try Again.';

    public const WAREHOUSE_SETTING_DISABLED_ERRORED_MESSAGE = 'Warehouse in the selected Template Disabled - Please choose an active warehouse for this product.';

    public const FBA_PRODUCT_ERRORED_MESSAGE = 'The selected action cannot be performed on this product because it is not fulfilled by merchant(FBM).';

    public const NOT_LISTED_PRODUCT_ERRORED_MESSAGE = 'The selected action cannot be performed on this product because product is not listed on Amazon.';

    public const FEED_NOT_GENERATED_ERRORED_MESSAGE = "Feed could'nt be generated. Kindly contact with our support.";

    public const FEED_LANGUAGE_ERRORED_MESSAGE = 'The language of feed is not English. Please change your language preference to English from your Amazon Seller Central account.';

    public const FEED_CONTENT_ERRORED_MESSAGE = 'Feed Content not found.';

    public const SHOP_NOT_FOUND_ERRORED_MESSAGE = 'Shop not found.';

    public const DEFAULT_ERROR_CODE = 'AmazonError001';
    
    public const FBA_NOT_AUTHORISED = "Seller is not Authorised to List on FBA";

    public const ONLYNOTLISTEDPRODUCT = 'Product is already Listed Kindly execute Sync Product Action';

    public const ONLYLISTEDPRODUCT = 'Sync Product can only be executed in Listed Product';


}