# Shopify logs
 
**mid :** merchant id

logs now been created on daily basis with date as directory name

**var/log/shopify/Requestcontrol/2020-05-02/Requestcontrol.log :** maintains record of mid which are being processed for bulk product import

**var/log/shopify/mail.log :** maintains record of mail response

**var/log/shopify/temp_inv_errorFlag_mid.log :** a rough estimation that something is erong with this mid's inventory id(s) probably invalid inventory item id existing

**var/log/shopify/mid/inventory/2020-05-02/webhook_inventory_update.log :** maintains logs for actions being performed during webhook inventory update data
   
**var/log/shopify/mid/inventory/2020-05-02/import_error.log :** maintains record if any error appears during import

**var/log/shopify/mid/inventory/2020-05-02/inventory.log :** maintains record for action being performed during inventory import

**var/log/shopify/mid/product/2020-05-02/webhook_product_import.log :** maintains logs for actions being performed during webhook product update

**var/log/shopify/mid/product/2020-05-02/webhook_product_delete.log :** maintains logs for actions being performed during webhook product delete

**var/log/shopify/mid/product/2020-05-02/product_import.log :** maintains logs for actions being performed during product import

**var/log/shopify/mid/product/2020-05-02/bulk_product_import.log :** maintains logs for actions being performed during bulk product delete
