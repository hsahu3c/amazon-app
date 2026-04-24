# Date : 25/Jan/2023

1. In the event that a marketplace is implementing a webhook through a connector, it is necessary to send the entire product to the connector along with the container_id and source_product_id to ensure proper functioning of the webhook.


# Date : 17/Jan/2023

## Changes in Importing
1. Version of Connector changed to `3.0.1` , EveryOne has to be call bin/update or setup:upgrade.After this, Indexing will be added automatically to required collections for better performance of Importing and Webhook. 
    -For marketplaces which are using temp_product_container for importing , has to define indexing manually
        - Indexes will be
            1. user_id , shop_id , container_id , type
            2. user_id , shop_id , source_product_id
2. Change the sequence of functions
3. Refactor of code
4. fix SKU duplicate code in ProductEvent.php
5. fix existing product checking process in ProductRequestControl.php
6. Reduce loop and queries
7. fix simpleOrVariantHandling() in ProductRequestControl (use only one child for checking Simple to Variant)
8. Change in array format of afterProductUpdate and afterVariantsDelete event.
   - afterProductUpdate Event
   This event is has information for new products added , new variants added , simple to variants product and variant to simple products

   ```
   $array = [
       'variant_to_simple' => [
           [container_id_1] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ],
           [container_id_2] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ]
       ],
       'simple_to_variant' => [
              [container_id_1] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ],
           [container_id_2] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ]
       ],
       'new_product' => [
               [container_id_1] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ],
           [container_id_2] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ]
       ],
       'new_variant' => [
              [container_id_1] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ],
           [container_id_2] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ]
   ]
   ```

   - afterVariantsDelete Event

   ```
   [
       [container_id_1] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ],
        [container_id_2] => [
               [source_product_id_1] => [
                ........
               ],
               [source_product_id_2] =>[
                ........
               ]
           ]
   ]
   ```

9. Fix parent checking process and parent creation process.
10. If any marketplace is send parent at connector then parent is created at connector from the first child . Other child will not create parent.
11. If any marketplace do not send `title` in formatted product then its parent will not create.
12. CheckIfWebhook present is only called when user_details has register webhook . It is checked from the user_details and a key is set `webhook_present` in sqs data.
13. Handle Queued Task and Notification in updateProgressBarAndAddNotification() function when any error occur at the time of importing.
14. use \_id for the update of products and upsert of new products.
15. fix checking of duplicate products .
16. Divide temp_db and main_db product_container queries as per marketplace requirement.
17. All mandatory data is prepared in bulkOpArray for checking,update and upsert of products properties. Data is prepared in a proper format so that isset can be used and keys which are not required in bulkWrite is unset before the query execution.

# Date : 26/Sep/2022

# Changes in Importing(Re-importing)

1. Variant To Simple Handling
2. Simple To Variant Handling
3. Deletion of deleted Variant or Insertion of new Variant
4. SKU Handling.
5. Entry of Marketplace and Entry of products in refine_products collection
6. Events of Deletion or Insertion of variants, new Product , Simple-to-Variant or Variant-to-Simple Products

**Note :**

1. To access the functions , $sqsdata must have [ 'isImported' = true] key .

```
    App\Connector\Components\Route\ProductRequestcontrol

    public function handleImport($sqsData)
    {
        ...
        ...
    }

    //$productArray = all formatted products used for event

    $this->simpleOrVariantHandling($SimpleOrVariantHandlingArr);
    $this->variantEvent($productsArray, $existData, $key, $productData, $eventData);

    $SimpleOrVariantHandlingArr = [
                        'existing_products' => $existData, // Existing Products in Database
                        'formatted_product' => $productData, //all formatted products
                        'additional_data' => $additional_data,
                        'bulkArrayWrite' => &$bulkArrayWrite, //array
                        'eventData' => &$eventData, //array
                        'parentMarketPlace' => &$parentMarketPlace, //used for updating marketplaces of products
                    ];

```

## Variant To Simple

If a product is changed from Variant to Simple then.

**Note :**

**Case 1 :** If no target is connected , Variant Product is converted To Simple Product and all its variant is delete and event will be provided to targetMarketplace.

**Case 2 :** If no target is connected and a default Variant is coming with Simple Product , if that default Variant has same source_product_id with any of its deleted variant , then that variant is deleted and Parent Product is changed to Simple Product. That deleted variant with same source_product_id is deleted and event will not be provided .

**Case 3 :** If target is connected and has uploaded or edited document , and product changed to simple, If that product has default Variant with same source_product_id any of its deleted variant, then Parent is changed to Simple Product and edited or uploaded document with same source_product_id will not be deleted. Same source_product_id variant will be deleted and event will not be given.
Function Used :

    App\Connector\Components\Route\ProductRequestcontrol

    /**
     * This function check after re-import a product is
     * changed from Simple to Variant or Vice-Versa
     * @param array $SimpleOrVariantHandlingArr
     * @return void
     */
    public function simpleOrVariantHandling(array $SimpleOrVariantHandlingArr): void
    {
       ....
       ....
    }

    /**
     * This function changes the Parent Product to Simple Product
     * @param object [mongo object] $existing_product
     * @param array $SimpleOrVariantHandlingArr
     * @return void
     */
    private function VariantToSimple(object $existing_product, $SimpleOrVariantHandlingArr): void
    {
       ....
       ....
    }

## Simple To Variant

**Note :** All Simple changed to Variant if barcode , source_product_id , sku does not match in case of default variant if any marketplace provide then edited or uploaded will be deleted. If any of these matches then uploaded or edited entry will be not be deleted.

**Case 1 :** If no target is connected and Product is changed Simple To Variant , then Simple product is changed To Parent Product if and a event is given to target.

**Case 2 :** If target is connected and Product is changed Simple To Variant , then Simple product is changed To Parent Product and target entry is deleted and a event is will not be provided .

    App\Connector\Components\Route\ProductRequestcontrol

    /**
     * This function check after re-import a product is
     * changed from Simple to Variant or Vice-Versa
     * @param array $SimpleOrVariantHandlingArr
     * @return void
     */
    public function simpleOrVariantHandling(array $SimpleOrVariantHandlingArr): void
    {
       ....
       ....
    }

     /**
     * This function change Simple Product to Parent Product
     *
     * @param object [mongo Object] $existing_product
     * @param array $SimpleOrVariantHandlingArr
     * @return void
     */
    private function simpleToVariant(object $existing_product, $SimpleOrVariantHandlingArr): void
    {
        ...
        ...
    }

## Deletion of deleted Variant or Insertion of new Variant

1. A Variants are inserted into the database and a event is provided.
2. If variants are deleted from source marketplace , then event is provided for the deleted variants.
3. A key **is_exists** will be set to products in the db which will found in the database and **is_exists** key will not set to variants which are deleted from source Marketplace .
4. When Importing ends and progress become 100% , after that a new sqs message will be pushed to product_delete Queue , This sqs queue will delete variants which has not is_exists key .

   ```
   App\Connector\Components\Route\ProductRequestcontrol

    public function pushToProductContainer($products, $additional_data = []){
        ...
        ...
        if ($additional_data['isImported']) {
                $this->setIsExistsToProducts($productData, $bulkOpArray);
            }
        ...
        ...

    }

    public function updateProgressBarAndAddNotification($sqsData){
        ...
        ...
        if (isset($sqsData['data']['isImported']) && $sqsData['data']['isImported']) {
            $this->createQueueOperation($sqsData);
        }
        ...
        ...
    }


   /**
    * This function Create a Sqs Queue For the deletion of
    * products(source products as well as uploaded products ),
    * that are deleted on source marketplace
    * After re-importing from marketplace
    * @param array $sqsData
    * @return array
    */
   private function createQueueOperation(array $sqsData): array
   {
       ...
       ...
   }

   /**
    * This function set is_exist key to products which are
    * found after re-importing from the source marketplace
    *
    * @param array $productData
    * @return array
    */
   private function setIsExistsToProducts(array $productData, &$bulkArray = []): array
   {
       ...
       ...
   }

   /**
    * This function unset is_exist key and delete products which
    * are deleted from source marketplace after re-import
    * @param array $sqsData
    * @return array
    */
   public function deleteIsExistsKeyAndProducts(array $sqsData): array
   {
    ....
    ....

   }
   ```

## SKU Handling

**Case 1 :** If sku or source_sku is not found then source_product_id will be assigned to the sku or source_sku respectively.

**Case 2 :** In case of Variant Product , Parent product check for parent_sku if not found then source_product_id will be assigned to the parent sku .

```
App\Connector\Components\ProductEvent

public function productSaveBefore(Event $event, $myComponent, $actualData){
    ...
}

 public function updateParentProduct($productData, $marketplace){
    ...
 }
```

```
$productData['sku'] = $productData['source_sku'] =  (!empty($productData['parent_sku'])) ? $productData['parent_sku'] : $parentProduct['source_product_id'];
```

```
$actualData['product_data']['source_sku'] = $actualData['product_data']['sku'] = (!empty($actualData['product_data']['sku'])) ? $actualData['product_data']['sku'] : $actualData['product_data']['source_product_id'];

```

## Entry of Marketplace

```
Connector->Components->ProductEvent->productSaveAfter(Event $event, $myComponent, $data)
```

Entry of Marketplace now done through productSaveAfter event and products also saved to refine_products collection

**Note :** All marketplace entries of above cases accordingly

```
App\Connector\Components\ProductEvent

//$data = product array

$eventsManager->fire('application:productSaveAfter', $this,  $data);

public function productSaveAfter(Event $event, $myComponent, $data)
    {
        ...
        ...
    }
```

## Events

**Note :** All TargetMarketplace has to hook these events for these information to be available.

Event Name :

1. afterProductUpdate

2. afterProductDelete

3. afterProductUpdate Event

   This event is has information for new products added , new variants added , simple to variants product and variant to simple products

   ```
   $array = [
       'variant_to_simple' => [
           0 => [
               ....
           ],
           1 => [
               ...
           ]
       ],
       'simple_to_variant' => [
            0 => [
               ...
           ],
           1 => [
               ...
           ]
       ],
       'new_product' => [
            0 => [
               ...
           ],
           1 => [
               ...
           ]
       ],
       'new_variant' => [
            0 => [
               ...
           ],
           1 => [
               ...
           ]
        ]
   ]
   ```

4. afterProductDelete Event

   This event gives all deleted variant as well as edited or uploading documents of same source_product_id in the chuncks of 75 or less.

   ```
   [
       0 => [

       ],
       1 => [

       ],
       2 => [

       ]
   ]
   ```
