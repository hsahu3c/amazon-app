<?php


namespace App\Amazon\Components\Report;

use Exception;
use MongoDB\BSON\ObjectID;
use App\Core\Components\Base;
use App\Amazon\Components\Product\Lookup;
use App\Amazon\Components\Cron\Route\Requestcontrol;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Models\SourceModel;
use App\Core\Models\User;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Cron\Helper as CronHelper;
use App\Connector\Models\QueuedTasks;

class SyncStatus extends Base
{
    public $mappedReportHeading = [
        "ï»¿item-name"=>"item-name",
        "ï»¿商品名" => "item-name",
        "ï»¿商品名称" => "item-name",
        "Nome dell'articolo" => "item-name",
        "Descrizione dell'articolo" => "item-description",
        "Numero offerta" => "listing-id",
        "SKU venditore" => "seller-sku",
        "Prezzo" => "price",
        "Quantit?" => "quantity",
        "Data di creazione" => "open-date",
        "Articolo per la vendita" => "item-is-marketplace",
        "Tipo di identificativo del prodotto" => "product-id-type",
        "Note sull'articolo" => "item-note",
        "Condizione dell'articolo" => "item-condition",
        "ASIN 1" => "asin1",
        "Spedizione internazionale" => "will-ship-internationally",
        "Spedizione Express" => "expedited-shipping",
        "Identificativo del prodotto" => "product-id",
        "Aggiungi o elimina" => "add-delete",
        "Cantidad" => "quantity",
        "SKU del vendedor" => "seller-sku",
        "Quantit? in sospeso" => "pending-quantity",
        "Canale di gestione" => "fulfillment-channel",
        "gruppo-spedizione-venditore" => "merchant-shipping-group",
        "stato" => "status",
        "Título del producto" => "item-name",
        "Tipo de identificador de producto" => "product-id-type",
        "Producto para vender" => "item-is-marketplace",
        "Precio" => "price",
        "Nota sobre el producto" => "item-note",
        "Identificador del producto" => "product-id",
        "Identificador del listing" => "listing-id",
        "Fecha de creación" => "open-date",
        "Estado del producto" => "status",
        "Envío urgente" => "expedited-shipping",
        "Envío internacional" => "will-ship-internationally",
        "Descripción del producto" => "item-description",
        "Columna en desuso" => "zshop-storefront-feature",
        "Cantidad pendiente" => "pending-quantity",
        "Canal de gestión logística" => "fulfillment-channel",
        "Añadir o eliminar" => "add-delete",
        "Quantit? minima per ordine" => "Minimum order quantity",
        "Vendi rimanenze" => "Inventory sale",
        "商品名" => "item-name",
        "出品ID" => "listing-id",
        "出品者SKU" => "seller-sku",
        "価格" => "price",
        "出品日" => "open-date",
        "商品IDタイプ" => "product-id-type",
        "コンディション説明" => "item-note",
        "コンディション" => "item-condition",
        "国外へ配送可" => "will-ship-internationally",
        "迅速な配送" => "expedited-shipping",
        "商品ID" => "product-id",
        "商品描述" => "item-description",
        "商品名称" => "item-name",
        "配送渠道" => "fulfillment-channel",
        "卖家配送组" => "merchant-shipping-group",
        "价格" => "price",
        "卖家 SKU" => "seller-sku",
        "数量" => "quantity",
        "商品备注" => "item-note",
        "等待购买数量" => "pending-quantity",
        "添加-删除" => "add-delete",
        "加急配送" => "expedited-shipping",
        "国际配送" => "will-ship-internationally",
        "商品状况" => "item-condition",
        "是否为商城中的商品" => "item-is-marketplace",
        "不适用项目" => "not-applicable-item",
        "开售日期" => "open-date",
        "商品编码" => "product-id",
        "在庫数" => "quantity",
        "フルフィルメント・チャンネル" => "fulfillment-channel",
        "ステータス" => "status",
        "estado"=>"status",
        "商品编码类型" => "product-id-type",
        "item-name (ürün adı)" => "item-name",
        "listing-id (liste kaydı numarası)" => "listing-id",
        "seller-sku (satıcı sku)" => "seller-sku",
        "price (fiyat)" => "price",
        "quantity (adet)" => "quantity",
        "open-date (yayınlanma tarihi)" => "open-date",
        "product-id-type (ürün numarası türü)" => "product-id-type",
        "item-note (ürün notu)" => "item-note",
        "item-condition (ürün durumu)" => "item-condition",
        "will-ship-internationally (uluslararası gönderilir)" => "will-ship-internationally",
        "expedited-shipping (ekspres kargo)" => "expedited-shipping",
        "product-id (ürün numarası)" => "product-id",
        "pending-quantity (bekleyen adet)" => "pending-quantity",
        "fulfillment-channel (dağıtım kanalı)" => "fulfillment-channel",
        "merchant-shipping-group (satıcı kargo grubu)" => "merchant-shipping-group",
        "durum" => "status",
        "Minimum sipariş adedi" => "Minimum order quantity",
        "Elde kalanı sat" => "Sell whats left",
        "اسم-المنتج" => "item-name",
        "وصف-المنتج" => "item-description",
        "رقم-العرض" => "listing-id",
        "sku-البائع" => "seller-sku",
        "السعر" => "price",
        "الكمية" => "quantity",
        "تاريخ-مفتوح" => "open-date",
        "منتج-سوق-البيع" => "will-ship-internationally",
        "نوع-رقم-المنتج" => "product-id-type",
        "ملاحظة-المنتج" => "item-note",
        "حالة-المنتج" => "item-condition",
        "للشحن-الدولي" => "Freight-international",
        "شحن-مستعجل" => "expedited-shipping",
        "رقم-المنتج" => "product-id",
        "إضافة-حذف" => "add-delete",
        "كمية-منتظرة" => "Quantity-to be expected",
        "قناة-الشحن" => "fulfillment-channel",
        "إعفاء-اختياري-لنوع-الدفع" => "Optional-Exempt-For-Type-Payment",
        "الحالة" => "status",
        "Angebotsnummer" => "listing-id",
        "Anmerkung zum Artikel" => "item-note",
        "Anzahl Bestellungen" => "Minimum order quantity",
        "Artikel ist Marketplace-Angebot" => "item-is-marketplace",
        "Artikelbeschreibung" => "item-description",
        "Artikelbezeichnung" => "item-name",
        "Artikelzustand" => "item-condition",
        "Erstellungsdatum" => "open-date",
        "Expressversand" => "expedited-shipping",
        "H舅dler-SKU" => "seller-sku",
        "H舅dlerversandgruppe" => "merchant-shipping-group",
        "Internationaler Versand" => "will-ship-internationally",
        "Menge" => "quantity",
        "Mindestbestellmenge" => "Minimum order quantity",
        "Preis" => "price",
        "Produkt-ID" => "product-id",
        "Produkt-ID-Typ" => "product-id-type",
        "Restposten verkaufen" => "Sell whats left",
        "Versender" => "fulfillment-channel",
        "Vendre le stock restant" => "Sell whats left",
        "canal-traitement" => "fulfillment-channel",
        "date-ouverture" => "open-date",
        "exp馘ition-international" => "will-ship-internationally",
        "groupe-exp馘ition-vendeur" => "merchant-shipping-group",
        "id-offre" => "listing-id",
        "id-produit" => "product-id",
        "livraison-express" => "expedited-shipping",
        "nom-produit" => "item-name",
        "note-騁at-article" => "item-note",
        "prix" => "price",
        "sku-vendeur" => "seller-sku",
        "type-id-produit" => "product-id-type",
        "騁at" => "status",
        "騁at-produit" => "item-condition",
        "Maksymalna liczba zamówionych produktów" => "Minimum order quantity",
        "Pozostało do sprzedaży" => "Sell whats left",
        "cena" => "price",
        "data-otwarcia" => "open-date",
        "dodaj-usuń" => "add-delete",
        "grupa-wysyłki-sprzedawcy" => "merchant-shipping-group",
        "identyfikator-oferty" => "listing-id",
        "identyfikator-produktu" => "product-id",
        "ilość" => "quantity",
        "ilość-oczekująca" => "pending-quantity",
        "kanał-realizacji" => "fulfillment-channel",
        "nazwa-produktu" => "item-name",
        "opis-produktu" => "item-description",
        "produkt-jest-(na)rynku" => "item-is-marketplace",
        "przyspieszona-wysyłka" => "expedited-shipping",
        "sku-sprzedawcy" => "seller-sku",
        "stan-produktu" => "item-condition",
        "typ-identyfikatora-produktu" => "product-id-type",
        "uwaga-o-produkcie" => "item-note",
        "wysyłka-międzynarodowa" => "will-ship-internationally",
        'Status' => 'status',
        "A?adir o eliminar" => 'add-delete',
        "Canal de gesti?n log?stica" => "fulfillment-channel",
        "Cantidad de pedido m?ima" => "Minimum order quantity",
        "Descripci?n del producto" => "item-description",
        "Vender las unidades restantes" => "Sell whats left",
        "T?ulo del producto" => "item-name",
        "Fecha de creaci?n" => "open-date",
        "Env? urgente" => "expedited-shipping",
        "Env? internacional" => "will-ship-internationally",
        "état" => "status",
        "quantité" => "quantity",
        'fulfilment-channel' => 'fulfillment-channel',
        'ASIN1' => 'asin1',
        '状态' => 'status',
    ];

    private ?string $userId = null;
    private $userDetails;
    private $baseMongo;
    private $sourceMarketplace = '';
    private $targetMarketplace = 'amazon';
    private $sourceShopId;
    private $targetShopId = '';
    private ?string $matchWith = null;
    private $lookup = false;
    private $initiated = '';
    private $initiateImage = true;
    /** Canonical keys expected on $product after the header row maps through mappedReportHeading (before asin1←product-id fallback). */
        public const REQUIRED_MAPPED_PRODUCT_KEYS = [
            'status',
            'fulfillment-channel',
            'asin1',
            'seller-sku',
            'item-name',
        ];

    public const TYPEMAP_OPTIONS = ['typeMap' => ['root' => 'array', 'document' => 'array']];

    public const GET_MERCHANT_LISTINGS_ALL_DATA_REPORT = 'GET_MERCHANT_LISTINGS_ALL_DATA';

    public const GET_V2_SELLER_PERFORMANCE_REPORT = 'GET_V2_SELLER_PERFORMANCE_REPORT';

    public const SELLER_PERFORMANCE_QUEUE_NAME = 'amazon_product_seller_performance_report';

    public const REPORT_REQUEST_OPERATION = 'request_report';

    public const REPORT_FETCH_OPERATION = 'fetch_report';

    public const IMPORT_REPORT_DATA_OPERATION = 'import_report_product';

    public const MATCH_STATUS_OPERATION = 'match_status';

    public const IMPORT_PRODUCT_IMAGE_OPERATION = 'import_image_product';

    public const MATCH_WITH_TITLE = 'title';

    public const MATCH_WITH_BARCODE = 'barcode';

    public const MATCH_WITH_SKU = 'sku';

    public const FULFILLED_BY_MERCHANT = 'FBM';

    public const FULFILLED_BY_AMAZON = 'FBA';

    public const MATCH_WITH_SKU_PRIORITY = 'sku_barcode';

    public const MATCH_WITH_BARCODE_PRIORITY = 'barcode_sku';

    public const PROCESS_AUTOMATIC = 'automatic';

    public const PROCESS_MANUAL = 'manual';

    public const DEFAULT_PAGINATION_IMAGE = 60;

    public const DEFAULT_LISTING_SAVE_LIMIT_SIZE = 500;

    public const DEFAULT_CHUNK_LISTING_FOR_MATCHING = 100;

    public const SYNC_STATUS_STEP_1_REPORT_GENERATE = 5;

    public const SYNC_STATUS_STEP_2_REPORT_FETCHNDOWNLOAD = 5;

    public const SYNC_STATUS_STEP_3_SAVE_LISTING = 10;

    public const SYNC_STATUS_MANUAL_WITHOUT_IMPORT_PROGRESS_START = 20;

    public const REMOTE_ERROR_SYNC_STATUS = 'Sync status request could not be submitted on Amazon. Kindly contact support.';

    public const SUCCESS_SYNC_STATUS = 'Product(s) Sync Status update successful.';

    public const ERROR_MESSAGE_SHOP_NOT_FOUND = 'Shop not found';

    public const ERROR_MESSAGE_INACTIVE_WAREHOUSE = 'Shop error : warehouse is not active.';

    public const SYNC_INITIATED_MESSAGE = 'Synchronization with Amazon Started';

    public const SYNC_ALREADY_IN_PROGRESS_MESSAGE = "Sync in progress. Please check the notification window for updates.";

    public const SYNC_STATUS_INITIATED_MESSAGE = "Sync process initiated. Please check the notification window for updates.";

    public const PERFORMANCE_REPORT_INITIATED_MESSAGE = 'Seller performance report initiated';

    public const SOMETHING_WENT_WRONG = 'Something went wrong!';

    public const FILE_NOT_FOUND = "Report not found during sync status processing. Please contact support for assistance.";

    public const NO_PRODUCTS_FOUND_ERROR = "Sync process halted: No products available for synchronization.";

    public const MATCH_IN_PROGRESS_MESSAGE = "Please wait while the sync status is being initiated. The app will update the product statuses once they match your Amazon Seller Central listings.";

    public const IMPORT_IN_PROGRESS_MESSAGE = "Success! Product saved for linking. Status synchronization will begin after product import is complete.";

    public const SETTINGS_NOT_FOUND = "Matching could not be initiated due to missing sync preferences. Sync process terminated.";

    public const SYNC_IN_PROGRESS = "Success! Status synchronization is now in progress.";

    public const MATCH_IN_PROGRESS_AFTER_IMPORT = "Success! Product saved. Linking and status synchronization are now in progress.";

    public const WAREHOUSE_INACTIVE_ERROR = 'Sync status cannot be processed as warehouse is not active!';

    public const ERROR_SYNC_STATUS = 'Sync status request could not be submitted on Amazon. Kindly contact support.';

    public const IMAGE_IMPORT_IN_PROGRESS_MESSAGE = 'Sync status is paused because image importing is in progress';

    /**
     * to initiate global variables
     */
    public function init($request = [])
    {

        $this->sourceMarketplace = $request['source_marketplace']['marketplace'] ?? ($request['data']['source_marketplace'] ?? null);
        $this->sourceShopId = $request['source_marketplace']['source_shop_id'] ?? ($request['data']['source_shop_id'] ?? null);
        if (!is_null($this->sourceShopId) && !is_string($this->sourceShopId)) {
            $this->sourceShopId = (string)$this->sourceShopId;
        }

        $this->targetMarketplace = $request['target_marketplace']['marketplace'] ?? ($request['data']['target_marketplace'] ?? null);
        $this->targetShopId = $request['target_marketplace']['target_shop_id'] ?? ($request['data']['home_shop_id'] ?? null);
        if (!is_null($this->targetShopId) && !is_string($this->targetShopId)) {
            $this->targetShopId = (string)$this->targetShopId;
        }
        //to classify if teh process is automatci or manual
        $this->initiated =  isset($request['initiated']) ? $request['initiated'] : self::PROCESS_AUTOMATIC;

        //setting up requester
        if (isset($this->sourceMarketplace, $this->sourceShopId, $this->targetMarketplace, $this->targetShopId)) {
            $data = [
                'Ced-Source-Id' => $this->sourceShopId,
                'Ced-Source-Name' => $this->sourceMarketplace,
                'Ced-Target-Id' => $this->targetShopId,
                'Ced-Target-Name' => $this->targetMarketplace
            ];
            $this->di->getObjectManager()->get('\App\Core\Components\Requester')->setHeaders($data);
        }

        $this->userId = isset($request['user_id']) ?  (string)$request['user_id'] : (string)$this->di->getUser()->id;
        //this should be true for the case when user onboards
        $this->lookup = isset($request['lookup']) ? $request['lookup'] : false;
        $this->initiateImage = isset($request['initiateImage']) ? $request['initiateImage'] : true;
        //for manual we need not to process the lookup
        if (isset($request['initiated']) && ($request['initiated'] == self::PROCESS_MANUAL)) {
            $this->lookup = false;
            $this->initiateImage = false;
        }

        $this->userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    /**
     * to initiate the sync status from a single point
     */
    public function startSyncStatus($payload)
    {
        try {
            if (!$this->isValidPayload($payload)) {
                return [
                    'success' => false,
                    'message' => 'Invalid params being passed'
                ];
            }
            $this->init($payload);
            $shopRes = $this->getActiveShop($this->targetShopId, $this->userId);
            if (isset($shopRes['success'], $shopRes['data']) && $shopRes['success']) {
                $shop = $shopRes['data'];
                $queuedTaskId =  $this->createQueuedTask();
                if ($queuedTaskId) {
                    $data = [
                        'queued_task_id' => $queuedTaskId,
                        'remote_shop_id' => $shop['remote_shop_id']
                    ];
                    if (!isset($payload['importProduct']) && isset($payload['initiated']) && ($payload['initiated'] == self::PROCESS_MANUAL)) {
                        $response = $this->executeWithoutImport($data);
                    } else {
                        $response = $this->executeWithImport($data);
                    }
                } else {
                    $response = [
                        'success' => false,
                        'message' => self::SYNC_ALREADY_IN_PROGRESS_MESSAGE
                    ];
                }
                return $response;
            }
            $response = $shopRes;
        } catch (Exception $e) {
            $this->addExceptionLog('startSyncStatus error: ' . json_encode($e->getMessage()));
            $response = [
                'success' => false,
                'message' => self::SOMETHING_WENT_WRONG
            ];
        }
        return $response;
    }

    /**
     * when sync status need to run without importing
     */
    public function executeWithoutImport($data)
    {
        try {
            $handlerData = $this->prepareSqsData(self::MATCH_STATUS_OPERATION, $data);
            //work needed --- 
            // $helper = $this->di->getObjectManager()->get(Helper::class);
            return $this->initiateSyncAfterImport($handlerData, true);
        } catch (Exception $e) {
            $this->addExceptionLog('executeWithoutImport exception: ' . json_encode($e->getMessage()));
            return ['success' => false, 'message' => self::SOMETHING_WENT_WRONG];
        }
    }

    /**
     * intiate the process of sync status
     */
    public function executeWithImport($data)
    {
        try {
            $handlerData = $this->prepareSqsData(self::REPORT_REQUEST_OPERATION, $data);
            // $this->addLog('handlerData: ' . json_encode($handlerData));
            $this->di->getMessageManager()->pushMessage($handlerData);
            if ($this->lookup) {
                /** execute seller-performance report in case of on-bording */
                $this->executePerformanceReport();
            }
            return ['success' => true, 'message' => self::SYNC_STATUS_INITIATED_MESSAGE];
        } catch (Exception $e) {
            $this->addExceptionLog('executeWithImport exception: ' . json_encode($e->getMessage()));
            return ['success' => false, 'message' => self::SOMETHING_WENT_WRONG];
        }
    }

    /**
     * valid payload check
     */
    public function isValidPayload($payload)
    {
        if (empty($payload)) {
            return false;
        }
        return (!empty($payload['source_marketplace']['marketplace']) || !empty($payload['data']['source_marketplace'])) &&
            (!empty($payload['source_marketplace']['source_shop_id']) || !empty($payload['data']['source_shop_id'])) &&
            (!empty($payload['target_marketplace']['marketplace']) || !empty($payload['data']['target_marketplace'])) &&
            (!empty($payload['target_marketplace']['target_shop_id']) || !empty($payload['data']['target_shop_id']));
    }

    /**
     * to get active shop data
     */
    public function getActiveShop($shopId, $userId)
    {
        try {
            $shop = $this->userDetails->getShop($shopId, $userId);
            if (empty($shop)) {
                return ['success' => false, 'message' => self::ERROR_MESSAGE_SHOP_NOT_FOUND];
            }
            if (isset($shop['warehouses'][0]['status']) && ($shop['warehouses'][0]['status'] == 'active')) {
                return ['success' => true, 'data' => $shop];
            } else {
                return ['success' => false, 'message' => self::ERROR_MESSAGE_INACTIVE_WAREHOUSE];
            }
        } catch (Exception $e) {
            $this->addExceptionLog('getActiveShop error: ' . json_encode($e->getMessage()));
            return ['success' => false, 'message' => self::SOMETHING_WENT_WRONG];
        }
    }

    /**
     * to prepare the queued task for the sync status
     */
    public function createQueuedTask()
    {
        $queueName = $this->di->getObjectManager()->get(CronHelper::class)->getProductReportQueueName();
        $appTag = $this->di->getAppCode()->getAppTag();
        $queueTaskData = [
            'user_id' => $this->userId,
            'message' => self::SYNC_INITIATED_MESSAGE,
            'process_code' => $queueName,
            'marketplace' =>  $this->targetMarketplace,
            'app_tag' => $appTag,
            'process_initiation' => $this->initiated,
            'additional_data' => [
                'initiateImage' => $this->initiateImage,
                'lookup' => $this->lookup
            ]
        ];
        $queuedTask = $this->di->getObjectManager()->get(QueuedTasks::class);
        return $queuedTask->setQueuedTask($this->targetShopId, $queueTaskData);
    }

    /**
     * to prepare the sqs data for the queue to process
     */
    public function prepareSqsData($action, $data = [])
    {
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $queueName = $data['queue_name'] ?? $this->di->getObjectManager()->get(CronHelper::class)->getProductReportQueueName();
        return [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => $data['method'] ?? 'processProductReport',
            'appCode' => $appCode,
            'appTag' => $appTag,
            'queue_name' => $queueName,
            'user_id' => $data['user_id'] ?? $this->userId,
            'lookup' => $data['lookup'] ?? $this->lookup,
            'initiateImage' => $data['initiateImage'] ?? false,
            'initiated' => $this->initiated,
            'data' => [
                'queued_task_id' => $data['queued_task_id'] ?? null,
                'home_shop_id' => $data['target_shop_id'] ?? $this->targetShopId,
                'remote_shop_id' => $data['remote_shop_id'] ?? null,
                'operation' => $action,
                'source_marketplace' => $data['source_marketplace'] ?? $this->sourceMarketplace,
                'target_marketplace' => $data['target_marketplace'] ?? $this->targetMarketplace,
                'source_shop_id' => $data['source_shop_id'] ?? $this->sourceShopId
            ]
        ];
    }

    /**
     * On transient Mongo/cluster errors ("No suitable servers found" / "Server error"), re-queue the same
     * Sync Status job using the payload's existing queue_name (no alternate queue). Skips if already a DB reattempt.
     */
    private function scheduleSyncStatusDbReattemptIfEligible( $payload,  $message)
    {
        try {
            if (!is_string($message) || (!str_contains($message, 'No suitable servers found') && !str_contains($message, 'Server error'))) {
                return;
            }
            if (!empty($payload['sync_status_db_reattempt'])) {
                return;
            }

            $reattemptPayload = $payload;
            $reattemptPayload['sync_status_db_reattempt'] = true;
            $reattemptPayload['queue_name'] = 'amazon_sync_status_reattempt';
            $reattemptPayload['delay'] = 300;
            unset($reattemptPayload['handle_added']);
            $this->di->getMessageManager()->pushMessage($reattemptPayload);
            $this->addLog('SyncStatus DB reattempt scheduled: ' . json_encode([
                'queue_name' => 'amazon_sync_status_reattempt',
                'operation' => $reattemptPayload['data']['operation'] ?? null,
                'exception' => $message,
            ]));
        } catch (Exception $e) {
            $this->addExceptionLog('scheduleSyncStatusDbReattemptIfEligible: ' . json_encode($e->getMessage()));
        }
    }

    /**
     * initiate the process for sync status process
     */
    public function process($sqsData)
    {
        try {
            $this->init($sqsData);
            $operationType = $sqsData['data']['operation'] ?? self::REPORT_REQUEST_OPERATION;
            $this->addLog('------ Process Cases ------- '.json_encode($sqsData));
            switch ($operationType) {
                case self::REPORT_REQUEST_OPERATION:
                    $this->requestProductReport($sqsData);
                    break;
                case self::REPORT_FETCH_OPERATION:
                    $this->fetchProductReport($sqsData);
                    break;
                case self::IMPORT_REPORT_DATA_OPERATION:
                    $this->saveAmazonListingProduct($sqsData);
                    break;
                case self::MATCH_STATUS_OPERATION:
                    $this->matchStatus($sqsData);
                    break;
                case self::IMPORT_PRODUCT_IMAGE_OPERATION:
                    if ($this->validateProcess($sqsData)) {
                        $this->saveAmazonListingImage($sqsData);
                    } else {
                        $this->addLog('------ saveAmazonListingImage Validation Failed ------- ');
                    }
                    break;
            }
            return true;
        } catch (Exception $e) {
            $this->addExceptionLog('error during sqs data processing: ' . json_encode($e->getMessage()));
            return false;
        }
    }


    /**
     * Step 1: genarate report for sync status
     */
    public function requestProductReport($sqsData): void
    {
        try {
            $queuedTask = $this->getSyncStatusQueuedTask($this->userId, ($sqsData['data']['queued_task_id'] ?? ''));
            if (!empty($queuedTask)) {
                // $this->addLog(' ---- requestProductReport ---- ');
                $counter = isset($sqsData['data']['counter']) ? $sqsData['data']['counter'] : 0;
                $response = $this->di->getObjectManager()->get(Report::class)->requestReport($sqsData['data']['remote_shop_id'] ?? '', $this->targetShopId, self::GET_MERCHANT_LISTINGS_ALL_DATA_REPORT);
                if ($this->isResponseThrottled($response) && $counter < 3) {
                    $this->addLog('requeiung as throttle case in requestProductReport: ' . json_encode($response));
                    $sqsData['data']['counter'] = $counter + 1;
                    $sqsData['delay'] = 60;
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return;
                }

                if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
                    foreach ($response['response'] as $result) {
                        if (!empty($result['ReportRequestId'])) {
                            $additionalData = $queuedTask['additional_data'] ?? [];
                            $additionalData['report_id'] = $result['ReportRequestId'];
                            $this->updateProcess($sqsData['data']['queued_task_id'], self::SYNC_STATUS_STEP_1_REPORT_GENERATE, 'Success! Sync Status Report request submitted on Amazon.', null, $additionalData);
                            break; //need to check why this is being written
                        }
                    }
                } elseif (!empty($sqsData['data']['queued_task_id'])) {
                    $this->addExceptionLog('remote fail in requestProductReport: ' . json_encode($response));
                    $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::REMOTE_ERROR_SYNC_STATUS);
                }
            }
        } catch (Exception $e) {
            $this->addExceptionLog('requestProductReport excpetion: ' . json_encode($e->getMessage()) . ' | ' . json_encode($sqsData));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * update task progress - modification requireds
     */
    public function updateProcess($queuedTaskId, $progress, $message = null, $severity = 'critical', $additionalData = null, $syncStatus = true)
    {
        if ($progress >= 100 && $message) {
            $cronHelper =  $this->di->getObjectManager()->get(CronHelper::class);
            $processCode = $syncStatus ? $cronHelper->getProductReportQueueName() : $cronHelper->imageImportQueueName();
            $notificationData = [
                'user_id' => $this->userId,
                'marketplace' => $this->targetMarketplace,
                'appTag' => $this->di->getAppCode()->getAppTag(),
                'message' => $message,
                'severity' => $severity,
                'process_initiation' => $this->initiated,
                'process_code' => $processCode
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($this->targetShopId, $notificationData);
        }

        if (!$message && $progress >= 100) {
            $message = self::ERROR_SYNC_STATUS;
        }
        $res = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, $progress, $message, true, $additionalData);
        return $res;
    }

    /**
     * to get the queued task
     */
    public function getSyncStatusQueuedTask($userId, $queuedTaskId = null)
    {
        $processCode = $this->di->getObjectManager()->get(CronHelper::class)->getProductReportQueueName();
        $queueTable = $this->baseMongo->getCollection('queued_tasks');
        if (!is_null($queuedTaskId)) {
            (is_string($queuedTaskId)) &&  $queuedTaskId = new ObjectId($queuedTaskId);
            return $queueTable->findOne(['_id' => $queuedTaskId], self::TYPEMAP_OPTIONS);
        }
        if ($this->targetShopId) {
            //  get queued task by process code for target_shop
            return $queueTable->findOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $this->targetShopId,
                    "process_code" => $processCode
                ],
                self::TYPEMAP_OPTIONS
            );
        }
        //  get all queued task of sourceShop by process code
        $appTag = $this->di->getAppCode()->getAppTag();
        return $queueTable->find([
            'user_id' => $userId,
            'appTag' => $appTag,
            "process_code" => $processCode
        ], self::TYPEMAP_OPTIONS);
    }

    /**
     * to execute the seller performance report
     */
    public function executePerformanceReport()
    {
        try {
            $shopRes = $this->getActiveShop($this->targetShopId, $this->userId);
            if (isset($shopRes['success'], $shopRes['data']) && $shopRes['success']) {
                $shop = $shopRes['data'];
                $queueName = self::SELLER_PERFORMANCE_QUEUE_NAME;
                $data = [
                    'queue_name' => $queueName,
                    'remote_shop_id' => $shop['remote_shop_id'],
                    'method' => 'processSellerPerformanceReport'
                ];
                $handlerData = $this->prepareSqsData(self::REPORT_REQUEST_OPERATION, $data);
                // $this->addLog('performance handlerData: ' . json_encode($handlerData));
                $this->di->getMessageManager()->pushMessage($handlerData);
                return ['success' => true, 'message' => self::PERFORMANCE_REPORT_INITIATED_MESSAGE];
            }
            return $shopRes;
        } catch (Exception $exception) {
            $this->addExceptionLog('executePerformanceReport exception: ' . json_encode($exception->getMessage()));
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * to request perfomance report
     */
    public function requestPerformanceReport($sqsData): void
    {
        try {
            if (is_null($this->userId)) {
                $this->init($sqsData);
            }
            $queuedTask = $this->getSyncStatusQueuedTask($this->userId, $sqsData['data']['queued_task_id']);
            if (!empty($queuedTask)) {
                // $this->addLog(' ---- requestPerformanceReport ---- ');
                // $this->addLog(' ---- sqsData ---- ' . json_encode($sqsData));
                $response = $this->di->getObjectManager()->get(Report::class)->requestReport($sqsData['data']['remote_shop_id'], $this->targetShopId, self::GET_V2_SELLER_PERFORMANCE_REPORT);
                $counter = isset($sqsData['data']['counter']) ? $sqsData['data']['counter'] : 0;
                if ($this->isResponseThrottled($response) && $counter < 3) {
                    $this->addLog('requeuing as throttle case in requestPerformanceReport: ' . json_encode($response));
                    $sqsData['data']['counter'] = $counter + 1;
                    $sqsData['delay'] = 60;
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return;
                }
                if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
                    foreach ($response['response'] as $result) {
                        if (!empty($result['ReportRequestId'])) {
                            $additionalData = $queuedTask['additional_data'] ?? [];
                            $additionalData['report_id'] = $result['ReportRequestId'];
                            $this->updateProcess($sqsData['data']['queued_task_id'], 20, 'Success! Performace Report request submitted on Amazon.', null, $additionalData);
                            break;
                        }
                    }
                } elseif (!empty($sqsData['data']['queued_task_id'])) {
                    $this->addExceptionLog('remote fail in requestPerformanceReport: ' . json_encode($response));
                    $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::REMOTE_ERROR_SYNC_STATUS);
                }
            }
        } catch (Exception $e) {
            $this->addExceptionLog('requestPerformanceReport exception: ' . json_encode($e->getMessage()));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Step 2: report fetch for sync status
     */
    public function fetchProductReport($sqsData): void
    {
        try {
            // queued task is still in progress and report is not proceesed yet by webhook
            if (isset($sqsData['data']['queued_task_id'], $sqsData['data']['report_id'], $sqsData['data']['remote_shop_id'])) {
                $path = BP . DS . 'var' . DS . 'file' . DS . 'mfa' . DS . $sqsData['user_id'] . DS . $this->targetShopId . DS . 'report.tsv';
                // $this->addLog(' --- fetchProductReport --- ');
                $counter = isset($sqsData['data']['counter']) ? $sqsData['data']['counter'] : 0;
                $response = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Report::class)->getReportById($sqsData['data']['remote_shop_id'],  $sqsData['data']['report_id']);
                if ($this->isResponseThrottled($response) && $counter < 3) {
                    $this->addLog('requeing as throttle case in fetchProductReport: ' . json_encode($response));
                    $sqsData['data']['counter'] = $counter + 1;
                    $sqsData['delay'] = 30;
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return;
                }

                if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
                    $this->addLog('response in fetchProductReport:  ' . json_encode($response));
                    foreach ($response['response'] as $result) {
                        if (!empty($result['report_document_url'])) {
                            //save report in file
                            $compressionAlgo = $result['compression_algorithm'] ?? null;
                            $filePath = $this->di->getObjectManager()->get(Report::class)->downloadReportData($result['report_document_url'], $path, $compressionAlgo);
                            // $this->addLog('downloadReportData response:  ' . json_encode($filePath));
                            if (is_string($filePath)) {
                                $sqsData['data']['operation'] = self::IMPORT_REPORT_DATA_OPERATION;
                                $sqsData['data']['skip_character'] = 0;
                                $sqsData['data']['chunk'] = self::DEFAULT_LISTING_SAVE_LIMIT_SIZE;
                                $sqsData['data']['file_path'] = $filePath;
                                $sqsData['data']['scanned'] = 0;
                                unset($sqsData['delay']);
                                // $this->addLog('sqsData:  ' . json_encode($sqsData));
                                $this->di->getMessageManager()->pushMessage($sqsData);
                                $this->updateProcess($sqsData['data']['queued_task_id'], self::SYNC_STATUS_STEP_2_REPORT_FETCHNDOWNLOAD, 'Success! Sync Status Report fetched from Amazon. Product Import currently in Progress.');
                            } else {
                                $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::ERROR_SYNC_STATUS);
                            }
                            break;
                        } elseif (isset($result['ReportProcessingStatus'])) {
                            if (in_array($result['ReportProcessingStatus'], ['IN_PROGRESS', 'IN_QUEUE'])) {
                                // $this->addLog('ReportProcessingStatus: ' . json_encode($result['ReportProcessingStatus']));
                                $sqsData['delay'] = 30;
                                $this->di->getMessageManager()->pushMessage($sqsData);
                            } else {
                                $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::REMOTE_ERROR_SYNC_STATUS);
                            }
                        } else {
                            $this->addLog('ReportProcessingStatus not set in result');
                            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::REMOTE_ERROR_SYNC_STATUS);
                        }
                        break;
                    }
                } elseif (!empty($sqsData['data']['queued_task_id'])) {
                    $this->addExceptionLog('remote fail in fetchProductReport: ' . json_encode($response));
                    $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::REMOTE_ERROR_SYNC_STATUS);
                }
            }
        } catch (Exception $e) {
            $this->addExceptionLog('fetchProductReport exception: ' . json_encode($e->getMessage()));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * After one data row is mapped into $product, returns required keys still absent (asin1 satisfied by asin1 or product-id before fallback).
     *
     * @return string[]
     */
    private function getMissingMappedProductKeys($product)
    {
        $missing = [];
        foreach (self::REQUIRED_MAPPED_PRODUCT_KEYS as $key) {
            if (!array_key_exists($key, $product)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Critical alert mail — same flow as Log::notifyThroughMail (mailer config, bcc/cc, cache throttle, getMailer::send).
     */
    private function notifyReportMappedColumnsThroughMail($sqsData, $missingMapped)
    {
        if (empty($missingMapped)) {
            return;
        }
        $missingList = implode(', ', $missingMapped);
        $sqsJson = json_encode($sqsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $content = '<p>Sync status report import: missing mapped product keys: <strong>'
            . htmlspecialchars($missingList, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p><strong>sqsData:</strong></p><pre style="white-space:pre-wrap;">'
            . htmlspecialchars($sqsJson, ENT_QUOTES, 'UTF-8') . '</pre>';

        $messageUniqueCode = 'syncstatus_missing_mapped_cols_' . $this->userId;
        $currentTimestamp = time();
        $config = $this->di->getConfig()->get('mailer');
        if (!$config) {
            return;
        }

        $email = $config->get('critical_mail_reciever') ?? '';
        $debug = 0;
        $isHtml = true;

        $subject = $config && $config->get('critical_mail_subject') ? $config->get('critical_mail_subject') : 'Critical issue';
        $appCode = $this->di->getConfig()->get('app_code');
        $subject = $subject . " - {$appCode}";

        $bccs = $config && $config->get('critical_mail_reciever_bcc') ? $config->get('critical_mail_reciever_bcc') : [];
        if (is_object($bccs)) {
            $bccs = $bccs->toArray();
        }

        $ccs = $config && $config->get('critical_mail_receiver_cc') ? $config->get('critical_mail_receiver_cc') : [];
        if (is_object($ccs)) {
            $ccs = $ccs->toArray();
        }

        if ($email === '') {
            return;
        }

        $lastMessageTime = $this->di->getCache()->get('sendmail_' . $messageUniqueCode);

        if (!$lastMessageTime || $lastMessageTime < $currentTimestamp - 3600) {
            $this->di->getCache()->set('sendmail_' . $messageUniqueCode, $currentTimestamp);
            $this->di->getMailer()->send($email, $subject, $content, [
                'debug' => $debug,
                'isHtml' => $isHtml,
                'bccs' => $bccs,
                'ccs' => $ccs,
            ]);
        }
    }

    /**
     * Step 3.1: Save amazon product's in amazon_listing
     */
    public function saveAmazonListingProduct($sqsData): void
    {
        try {
            $this->addLog(' --- saveAmazonListingProduct ---');
            // $this->addLog('sqsData: ' . json_encode($sqsData));
            $filePath = $sqsData['data']['file_path'] ?? null;
            $linesScanned = $sqsData['data']['scanned'] ?? 0;
            $index = 0;
            // $statusArray = [];
            clearstatcache();
            if (!$filePath || !file_exists($filePath) || ($reportFile = fopen($filePath, 'r')) === false) {
                $this->addLog('file not exist cannot proceed');
                //need to update message
                $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::FILE_NOT_FOUND);
                return;
            }
            // how many line need to Read
            $rowChunk = $sqsData['data']['chunk'] ?? self::DEFAULT_LISTING_SAVE_LIMIT_SIZE;
            $skipCharacter = $sqsData['data']['skip_character'] ?? 0;
            $bulkOpArray = [];
            $mappedProductColumnsValidated = $sqsData['data']['missing_mapped_columns'] ?? false;
            // $head = fgets($handle);
            $headerLine = fgets($reportFile) ?: '';
            $heading = explode("\t", trim($headerLine)); //to get teh header
            // it will help you the Skip the charater which are already been used
            fseek($reportFile, $skipCharacter);
            // loop through the file line-by-line
            while (($splitContents = fgets($reportFile)) !== false && $index < $rowChunk) {
                $lines = explode("\n", $splitContents);
                $line = isset($lines[0]) ? $lines[0] : '';
                //scanning each line and prearing the product data
                if ($linesScanned > 0) {
                    $columnData = explode("\t", $line);
                    if (count($columnData) == count($heading)) {
                        $product = [];
                        foreach ($heading as $pointer => $keyName) {
                            if (!empty($columnData[$pointer])) {
                                $detectedEncoding = mb_detect_encoding(
                                    $columnData[$pointer],
                                    ['ASCII', 'UTF-8', 'Windows-1252', 'ISO-8859-1', 'SJIS'],
                                    true
                                );
                                if ($detectedEncoding && $detectedEncoding !== 'ASCII' && $detectedEncoding !== 'UTF-8') {
                                    $columnData[$pointer] = mb_convert_encoding(
                                        $columnData[$pointer], 'UTF-8', $detectedEncoding
                                    );
                                } elseif (!$detectedEncoding) {
                                    $columnData[$pointer] = mb_convert_encoding(
                                        $columnData[$pointer], 'UTF-8', 'Windows-1252'
                                    );
                                }
                            }

                            if (mb_detect_encoding($keyName, ['ASCII', 'UTF-8', 'SJIS', 'Windows-1252', 'ISO-8859-1'], false)) {
                                $encodedKey = mb_convert_encoding($keyName, 'UTF-8', mb_detect_encoding($keyName, ['ASCII', 'UTF-8', 'SJIS', 'Windows-1252', 'ISO-8859-1'], false));
                                $keyName = iconv('UTF-8', 'UTF-8//IGNORE', $encodedKey);
                            } else {
                                $keyName = 'undetected_key' . $pointer;
                            }

                            if (isset($this->mappedReportHeading[$keyName])) {
                                $product[$this->mappedReportHeading[$keyName]] = $columnData[$pointer];
                            } else {
                                $product[$keyName] = $columnData[$pointer];
                            }
                        }

                        if (!isset($product['asin1']) && isset($product['product-id'])) {
                            $product['asin1'] = $product['product-id'];
                        }

                        if (!$mappedProductColumnsValidated) {
                            // $this->addLog('validating mapped product columns'.json_encode($product));
                            $missingMapped = $this->getMissingMappedProductKeys($product);
                            $this->addLog('missing mapped columns: '.json_encode($missingMapped));
                            if (!empty($missingMapped)) {
                                $this->notifyReportMappedColumnsThroughMail($sqsData, $missingMapped);
                                // $this->addLog("Missing required columns in report headers");
                                $this->updateProcess($sqsData['data']['queued_task_id'], 100, "Report headers missing required columns");
                                return;
                            }
                            $mappedProductColumnsValidated = true;
                            $sqsData['data']['missing_mapped_columns'] = $mappedProductColumnsValidated;
                        }

                        if (isset($product['image-url']) && $product['image-url'] == '') {
                            unset($product['image-url']);
                        }

                        $product['updated_at'] = date('c');
                        $product['source_marketplace'] = $this->sourceMarketplace;
                        $product['user_id'] = $sqsData['user_id'];
                        $product['shop_id'] = (string)$sqsData['data']['home_shop_id'];
                        if (isset($product['seller-sku'])) {
                            $filter = ['user_id' => (string)$sqsData['user_id'], 'shop_id' => (string)$sqsData['data']['home_shop_id'], 'seller-sku' => (string)$product['seller-sku']];
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    $filter,
                                    ['$set' => $product],
                                    ['upsert' => true],
                                ],
                            ];
                        }
                    }
                }
                $index++;
                $skipCharacter = ftell($reportFile);
                $linesScanned++;
            }

            if (!empty($bulkOpArray)) {
                $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
                $amazonCollection->BulkWrite($bulkOpArray, ['w' => 1]);
            }

            $isEOF = feof($reportFile);
            if ($isEOF || ($index < $rowChunk - 3) || ($linesScanned == $sqsData['data']['scanned'])) {
                $this->addLog('file end ---');
                unlink($filePath);
                unset($sqsData['data']['skip_character'], $sqsData['data']['chunk'], $sqsData['data']['file_path'], $sqsData['data']['scanned'], $sqsData['data']['failed_asin'], $sqsData['data']['failed_skus']);
                $this->afterProductReportSaved($sqsData);
            } else {
                $sqsData['data']['scanned'] = $linesScanned;
                $sqsData['data']['skip_character'] = $skipCharacter;
                unset($sqsData['delay']);
                // $this->addLog('sqsData to send in save amazon listing: ' . json_encode($sqsData));
                $this->di->getMessageManager()->pushMessage($sqsData);
            }
        } catch (Exception $e) {
            $this->addExceptionLog('saveAmazonListingProduct exception: ' . json_encode($e->getMessage()));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * once products gets saved in amazon_listing we need to perform matching and update product status to deleted if products not found
     */
    public function afterProductReportSaved($sqsData)
    {
        $sqsData['amazon_import'] = true;
        // $this->addLog('afterProductReportSaved: ' . json_encode($sqsData));
        /** to update the status of such products whose status is not being updated while sync status report gets saved */
        $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class)->updateDeleteStatus($sqsData);
         /** to initiate image import */
        //point to be discuss- since we need to import image during the process of save amazon listing we need to do it tehre as well so that in the meantime the product's parent status also get updated
        if (isset($sqsData['initiateImage']) && $sqsData['initiateImage']) {
            $this->addLog('initiateImage started');
            $this->initiateImageImport($sqsData);
        }
        $this->initiateSyncAfterImport($sqsData);

    }

    /**
     * Step 3.2: initaiting of matching after product save in amazon_listing
     */
    public function initiateSyncAfterImport($data, $directInitiation = false)
    {
        try {
            if (is_null($this->targetShopId) || is_null($this->userId) || is_null($this->sourceShopId)) {
                if ($this->isValidPayload($data)) {
                    $this->init($data);
                } else {
                    return [
                        'success' => false,
                        'message' => 'Invalid data provided to process'
                    ];
                }
            }
            // $this->addLog(' -- initiateSyncAfterImport -- ');
            if (isset($data['data']['queued_task_id'])) {
                $searchResponse = $this->getSyncStatusQueuedTask($this->userId, $data['data']['queued_task_id']);
                $handlerData = $data;
            } elseif (isset($data['source_marketplace'], $data['target_marketplace'], $data['user_id'])) {
                $this->addLog('received from after products import --- ');
                /** case when re-direct after shopify-product-import on-boarding*/
                $searchResponse = $this->getSyncStatusQueuedTask($this->userId);
                if (!empty($searchResponse) && isset($searchResponse['additional_data']['amazon_import']) && $searchResponse['additional_data']['amazon_import']) {
                    // $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $shopRes = $this->getActiveShop($this->targetShopId, $this->userId);
                    if (isset($shopRes['success'], $shopRes['data']) && !empty($shopRes['data'])) {
                        $shop = $shopRes['data'];
                        $data = [
                            'queued_task_id' => (string)$searchResponse['_id'],
                            'remote_shop_id' => $shop['remote_shop_id'] ?? null
                        ];
                        $handlerData = $this->prepareSqsData(self::MATCH_STATUS_OPERATION, $data);
                        /** lookup true for on-boarding since this case will occur from the afterProductImport case*/
                        $handlerData['lookup'] = true;
                        // $this->addLog('handlerData: ' . json_encode($handlerData));
                    } else {
                        // $this->addLog('shop not found or warehouse not active -- ');
                        $this->updateProcess((string)$searchResponse['_id'], 100, self::WAREHOUSE_INACTIVE_ERROR);
                        return [
                            'success' => false,
                            'message' => 'No active shop is found!'
                        ];
                    }
                } else {
                    $this->addLog('amazon importing not completed -- ');
                    return [
                        'success' => false,
                        'message' => 'Amazon importing is still in progress!'
                    ];
                }
            }

            /** initiate match status */
            /** get total count of amazon-products (matching process) for dynamic progress track in queued task */
            $amazonCollection = $this->baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
            $query = [
                'user_id' => (string)$handlerData['user_id'],
                'shop_id' => (string)$handlerData['data']['home_shop_id'],
            ];
            $productsCount = $amazonCollection->countDocuments($query);

            /** if amazon-listing has product(s) for matching */
            if ($productsCount > 0) {
                /** case when re-direct from saveAmazonListingProduct after amazon-product-import */
                if (isset($handlerData['initiateImage'])) {
                    $queueName = $this->di->getObjectManager()->get(CronHelper::class)->imageImportQueueName();
                    $imageImportTask = $this->di->getObjectManager()->get(QueuedTasks::class)->checkQueuedTaskwithProcessTag($queueName, (string)$this->targetShopId);
                    if (!empty($imageImportTask)) {
                        $this->addLog('imageImportTask found ');
                        $progress = self::SYNC_STATUS_STEP_3_SAVE_LISTING;
                        if (!empty($searchResponse) && ($searchResponse['progress'] >= 20)) {
                            $progress = 0;
                        }
                        $this->updateProcess($handlerData['data']['queued_task_id'], $progress, self::IMAGE_IMPORT_IN_PROGRESS_MESSAGE, 'success'); 
                        return [
                            'success' => false,
                            'message' => self::IMAGE_IMPORT_IN_PROGRESS_MESSAGE
                        ];

                    }
                }

                if (!empty($handlerData['lookup']) && $handlerData['lookup']) {
                    /** check if shopify product import is in progress - in on-boarding case */
                    // $importingTask = $this->di->getObjectManager()->get(QueuedTasks::class)->checkQueuedTaskwithProcessTag('saleschannel_product_import', (string)$handlerData['data']['source_shop_id']);
                    // if (empty($importingTask)) {
                    //     $importingTask = $this->di->getObjectManager()->get(QueuedTasks::class)->checkQueuedTaskwithProcessTag('product_import', (string)$handlerData['data']['source_shop_id']);
                    // }

                        $importingTask = false;
                    
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
                        $sendToDB = [
                            'user_id' => (string)$handlerData['user_id'], 
                            'shop_id' => (string)$handlerData['data']['source_shop_id'], 
                            'process_code' => ['$in' => ['saleschannel_product_import' , 'product_import']]
                        ];
                        $res = $queuedTasks->findOne($sendToDB);
                        
                        if(!empty($res)){
                            $importingTask = true;
                            if(!empty($res['additional_data']['process_label'] ) && $res['additional_data']['process_label'] == 'Shopify Batch Product Import'){
                                $importingTask = false;
                            }
                        }


                    if ($importingTask) {
                        $additionalData = $handlerData['additional_data'];
                        $additionalData['amazon_import'] = true;
                        $this->addLog('importingTask is still in progress.');
                        $progress = self::SYNC_STATUS_STEP_3_SAVE_LISTING;
                        if (!empty($searchResponse) && ($searchResponse['progress'] >= 20)) {
                            $progress = 0;
                        }
                        $this->updateProcess($handlerData['data']['queued_task_id'], $progress, self::IMPORT_IN_PROGRESS_MESSAGE, 'success', $additionalData);
                        return [
                            'success' => false,
                            'message' => self::IMPORT_IN_PROGRESS_MESSAGE
                        ];
                    }
                }

                /** get sync-preference settings */
                $syncSettings = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class)->getProductSettingForSync($this->userId, $this->sourceShopId, $this->targetShopId, $this->sourceMarketplace, $this->targetMarketplace);
                if (empty($syncSettings)) {
                    $this->addLog('syncSettings not found');
                    $this->updateProcess($handlerData['data']['queued_task_id'], 100, self::SETTINGS_NOT_FOUND);
                    return [
                        'success' => false,
                        'message' => self::SETTINGS_NOT_FOUND
                    ];
                } else {
                    $handlerData['data']['sync_preference'] = $syncSettings;
                    $handlerData['data']['individual_weight'] =  $productsCount;
                    $handlerData['data']['page'] = 0;
                    $handlerData['data']['limit'] = self::DEFAULT_LISTING_SAVE_LIMIT_SIZE;
                    $handlerData['data']['operation'] = self::MATCH_STATUS_OPERATION;
                    $handlerData['data']['match'] = true;
                    unset($handlerData['delay']);
                    // $this->addLog('handlerData: ' . json_encode($handlerData));
                    $this->di->getMessageManager()->pushMessage($handlerData);
                    $message = $directInitiation ? self::SYNC_IN_PROGRESS : self::MATCH_IN_PROGRESS_AFTER_IMPORT;
                    $progress = $directInitiation ? self::SYNC_STATUS_MANUAL_WITHOUT_IMPORT_PROGRESS_START : self::SYNC_STATUS_STEP_3_SAVE_LISTING;
                    if (!empty($searchResponse) && ($searchResponse['progress'] >= 20)) {
                        $progress = 0;
                    }
                    if (isset($sqsData['amazon_import'])) {
                        $additionalData['amazon_import'] = true;
                        $this->updateProcess($handlerData['data']['queued_task_id'], $progress, $message, 'success', $additionalData);
                    } else {
                        $this->updateProcess($handlerData['data']['queued_task_id'], $progress, $message, 'success');
                    }
                    return [
                        'success' => true,
                        'message' => $message
                    ];
                }
            } else {
                $this->updateProcess($handlerData['data']['queued_task_id'], 100, self::NO_PRODUCTS_FOUND_ERROR, 'success');
                return [
                    'success' => true,
                    'message' => self::NO_PRODUCTS_FOUND_ERROR
                ];
            }
            return [
                'success' => true,
                'message' => self::MATCH_IN_PROGRESS_MESSAGE
            ];
        } catch (Exception $e) {
            $this->addExceptionLog('Exception in initiateSyncAfterImport: ' . json_encode($e->getMessage()));
        }
    }

    /** initiate process of image-import from amazon for amazon-listing */
    public function initiateImageImport($sqsData)
    {
        try {
            $this->addLog(' --- in initiateImageImport --- ' . json_encode($sqsData));
            if (is_null($this->targetShopId) || is_null($this->sourceShopId) || is_null($this->userId)) {
                if ($this->isValidPayload($sqsData)) {
                    $this->init($sqsData);
                } else {
                    //case need to be handled or log need to be added
                    $this->addLog('invalid process');
                    return false;
                }
            }
            $matchStatusData = [];
            if (isset($sqsData['amazon_import']) && $sqsData['amazon_import']) {
                $matchStatusData = $sqsData;
            }
            // $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $amazonCollection = $this->baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
            $query = [
                'user_id' => (string)$sqsData['user_id'],
                'shop_id' => (string)$sqsData['data']['home_shop_id'],
                'image-url' => null,
                'seller-sku' => ['$nin' => [null, '']]
            ];
            $eligibleProducts = $amazonCollection->count($query);
            $this->addLog('eligibleProducts: ' . json_encode($eligibleProducts));
            if ($eligibleProducts > 0) {
                $queueName = $this->di->getObjectManager()->get(CronHelper::class)->imageImportQueueName();
                $appTag = $this->di->getAppCode()->getAppTag();
                $queueData = [
                    'user_id' => $sqsData['user_id'],
                    'message' => "Synchronization of Amazon Listing's images with Amazon Started",
                    'process_code' => $queueName,
                    'marketplace' =>  'amazon',
                    'app_tag' => $appTag
                ];
                $queuedTaskId = $this->di->getObjectManager()->get(QueuedTasks::class)->setQueuedTask((string)$sqsData['data']['home_shop_id'], $queueData);
                if ($queuedTaskId) {
                    $options = ['projection' => ['_id' => 1], 'sort' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']];
                    $firstIndex = $amazonCollection->findOne($query, $options);
                    $sqsData['data']['queued_task_id'] = $queuedTaskId;
                    $sqsData['queue_name'] = $queueName;
                    unset($sqsData['handle_added']);
                    $sqsData['data']['operation'] = self::IMPORT_PRODUCT_IMAGE_OPERATION;
                    $sqsData['data']['page'] = 0;
                    $sqsData['data']['limit'] = self::DEFAULT_PAGINATION_IMAGE;
                    // $sqsData['data']['lastPointer'] = (string)$firstIndex['_id'] ?? '';
                    $sqsData['data']['individual_weight'] = ceil($eligibleProducts / self::DEFAULT_PAGINATION_IMAGE);
                    unset($sqsData['delay']);
                    if (!empty($matchStatusData)) {
                        $sqsData['match_status_data'] = $matchStatusData;
                        $this->addLog('sqsData in image import: '.json_encode($sqsData));
                    }
                    // $this->addLog('sqsData: ' . json_encode($sqsData));
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            $this->addExceptionLog('initiateImageImport Exception: ' . json_encode($e->getMessage()));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Step 4: matching and updating status of products after product save or resyncing the statuses and product linking
     */
    public function matchStatus($sqsData)
    {
        try {
            if (($sqsData['user_id'] !== false) && isset($sqsData['data']['home_shop_id']) && $sqsData['data']['home_shop_id']) {
                // $this->addLog('sqsData: ' . json_encode($sqsData));
                $userId = $sqsData['user_id'];
                $limit = isset($sqsData['data']['limit']) ? $sqsData['data']['limit'] : self::DEFAULT_LISTING_SAVE_LIMIT_SIZE;
                $skip = isset($sqsData['data']['page']) ? $sqsData['data']['page'] : 0;
                if (!$this->validateProcess($sqsData)) {
                    $this->addLog('validateProcess returns false');
                    return true;
                }
                $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
                $query = [
                    'user_id' => (string)$userId,
                    'shop_id' => (string)$this->targetShopId,
                ];
                $options = [
                    'projection' => [
                        'product-id' => 1,
                        'seller-sku' => 1,
                        'status' => 1,
                        'asin1' => 1,
                        'asin2' => 1,
                        'asin3' => 1,
                        'item-name' => 1,
                        'source_product_id' => 1,
                        'closeMatchedProduct' => 1,
                        'type' => 1,
                        'automate_sync' => 1,
                        'automate_status_update' => 1,
                        'fulfillment-channel' => 1,
                        'matched'=>1,
                        'matchedProduct' => 1,
                        'manual_mapped' => 1,
                        'matchedwith' => 1,
                        'source_container_id' => 1,
                        'amazonStatus'=>1
                    ],
                    'sort' => ['_id' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => $limit,
                    'skip' => $skip
                ];
                if (
                    isset($sqsData['data']['sync_preference'][self::FULFILLED_BY_AMAZON]) &&
                    !isset($sqsData['data']['sync_preference'][self::FULFILLED_BY_MERCHANT])
                ) {
                    $query['fulfillment-channel'] = ['$ne' => 'DEFAULT'];
                } elseif (
                    isset($sqsData['data']['sync_preference'][self::FULFILLED_BY_MERCHANT]) &&
                    !isset($sqsData['data']['sync_preference'][self::FULFILLED_BY_AMAZON])
                ) {
                    $query['fulfillment-channel'] = 'DEFAULT';
                }

                if (isset($sqsData['data']['match']) && $sqsData['data']['match']) {
                    /**
                     * since we need to find out the actual weight of the products to properly track the process so when running this for teh first time we will get teh overall count
                     */
                    $totalProducts = $amazonCollection->countDocuments($query);
                    $sqsData['data']['individual_weight'] = $totalProducts;
                    unset($sqsData['data']['match']);
                } else {
                    $totalProducts = $sqsData['data']['individual_weight'];
                }
                $this->addLog('totalProducts: ' . json_encode($totalProducts));
                $amazonListing = $amazonCollection->find($query, $options);
                $listing = $amazonListing->toArray();
                $listingCount = count($listing);
                // $this->addLog('listingCount: ' . json_encode($listingCount));
                if ($listingCount > 0) {
                    $dataRequiredToMatch = [];
                    $dataRequiredToMatch['shop_id'] = $this->targetShopId;
                    $dataRequiredToMatch['source_shop_id'] = $this->sourceShopId;
                    $dataRequiredToMatch['sync_preference'] = $sqsData['data']['sync_preference'] ?? [];
                    $dataRequiredToMatch['source_marketplace'] = $this->sourceMarketplace;
                    $updateStatusAndMatchRes = $this->updateStatusAndMatch($listing, $dataRequiredToMatch);
                    // $this->addLog('updateStatusAndMatchRes: ' . json_encode($updateStatusAndMatchRes));
                }

                //we also need to consider teh case of the not any finding in some chunks so we need to consider this as well
                if ($listingCount >= $limit) {
                    $next = (int)$limit;
                    $sqsData['data']['page'] = (int)($skip + $next);
                    $sqsData['data']['limit'] = $limit;
                    $progress = round((($skip + $limit) / $totalProducts) * 80 + 20, 2); //(since we are working on a scale of 20/80, where 20 covers the progress from report get, fetch and save and rest 80 this is being done due to that)
                    if ($progress < 100) {
                        // $this->addLog('progress: ' . json_encode($progress));
                        // $this->addLog('sqsData: ' . json_encode($sqsData));
                        unset($sqsData['delay']);
                        $this->di->getMessageManager()->pushMessage($sqsData);
                        $this->di->getObjectManager()->get(QueuedTasks::class)->updateFeedProgress($sqsData['data']['queued_task_id'], $progress, '', false, $skip + $limit);
                        return true;
                    }
                }
                /**
                 * this will be done only at the time of onboarding so that the products which are not processed during matching all these can be added for not listed offer
                 */
                if (isset($sqsData['lookup']) && $sqsData['lookup']) {
                    $this->addLog('lookup initiated');
                    /** initiate look-up for on-boarding case */
                    $preparedData = [
                        'source_marketplace' => [
                            'source_shop_id' => $sqsData['data']['source_shop_id'], // source shop id
                            'marketplace' => $sqsData['data']['source_marketplace'] // source
                        ],
                        'target_marketplace' => [
                            'target_shop_id' => $sqsData['data']['home_shop_id'], // amazon shop id
                            'marketplace' => $sqsData['data']['target_marketplace'] // amazon
                        ],
                        "user_id" => $this->userId,
                    ];
                    $this->di->getObjectManager()->get(Lookup::class)->init($preparedData)->initiateLookup($preparedData);
                }
                $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::SUCCESS_SYNC_STATUS, 'success');
                return true;
            }
        } catch (Exception $e) {
            $this->addExceptionLog('matchstatus exception: ' . json_encode($e->getMessage()));
            $this->scheduleSyncStatusDbReattemptIfEligible($sqsData, $e->getMessage());
            throw $e;
        }
    }

    /**
     * to check teh valid queued task entry in teh db
     */
    public function validateProcess($sqsData)
    {
        try {
            $queueTable = $this->baseMongo->getCollection('queued_tasks');
            $searchResponse = $queueTable->find(['_id' => new ObjectId($sqsData['data']['queued_task_id'])], ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
            if (!empty($searchResponse)) {
                return true;
            }
        } catch (Exception $e) {
            $this->addExceptionLog('Exception validateProcess ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true));
        }
        return false;
    }

    /**
     * Step 4.1: update status and performing auto mapping
     */
    public function updateStatusAndMatch($amazonProducts, $dataPrepared)
    {
        try {
            //first we need to divide them in a chunk and after that we will group them in manual mapped products and based on teh fulfilmment type based on teh sync prefernces
            $chunkedProducts = array_chunk($amazonProducts, self::DEFAULT_CHUNK_LISTING_FOR_MATCHING);
            $syncInventoryIds = [];
            foreach ($chunkedProducts as $chunkIndex => $products) {
                $groupedProducts = $this->groupProductsForMatching($products, $dataPrepared);
                if (!empty($groupedProducts['matchedProduct'])) {
                    /**
                     * here we need to only update the status for newly updated statuses of all the linked products
                     */
                    $this->processManualProductsToUpdateStatus($groupedProducts['matchedProduct']);
                }
                if (!empty($groupedProducts['fba'])) {
                    $syncPreference = $dataPrepared['sync_preference'][self::FULFILLED_BY_AMAZON] ?? 'sku';
                    $syncInventoryIds += $this->processProductsToUpdateStatusAndLinking($groupedProducts['fba'], $products, $syncPreference);
                }
                if (!empty($groupedProducts['fbm'])) {
                    $syncPreference = $dataPrepared['sync_preference'][self::FULFILLED_BY_MERCHANT] ?? 'sku';
                    $syncInventoryIds += $this->processProductsToUpdateStatusAndLinking($groupedProducts['fbm'], $products, $syncPreference);
                }
            }
            // $this->addLog('syncInventoryIds: ' . json_encode($syncInventoryIds));

            if (!empty($syncInventoryIds)) {
                // $notification = $this->di->getObjectManager()->get('\App\Connector\Models\Notifications');
                // $message = 'Inventory sync initiated for ' . count($syncInventoryIds) . ' product(s).';
                // $notificationData = [
                //     "user_id" => $this->userId,
                //     "marketplace" => $this->targetMarketplace,
                //     "appTag" => $this->di->getAppCode()->getAppTag(),
                //     "message" => $message,
                //     "severity" => "success",
                //     "process_code" => "inventory_sync",
                //     'process_initiation' => $this->initiated
                // ];
                // // $this->addLog('syncInventoryIds in: ' . json_encode($syncInventoryIds));

                // $notification->addNotification($this->targetShopId, $notificationData);
                // unset($notification->_id);
                /** initiate inventory sync */
                $dataToSent = [
                    'source' => [
                        'shopId' => $this->sourceShopId,
                        'marketplace' => $this->sourceMarketplace
                    ],
                    'target' => [
                        'shopId' => $this->targetShopId,
                        'marketplace' => $this->targetMarketplace
                    ],
                    'user_id' => $this->userId
                ];
                // $this->addLog('dataToSent: ' . json_encode($dataToSent));
                $reportHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
                $syncInventoryIds = array_map('strval', array_keys($syncInventoryIds));
                // $this->addLog('syncInventoryIds: ' . json_encode($syncInventoryIds));

                $reportHelper->inventorySyncAfterLinking($syncInventoryIds, $dataToSent);
            }
        } catch (Exception $e) {
            $this->addExceptionLog('Exception in updateStatusAndMatch: '.json_encode($e->getMessage()));
            throw $e;
        }
    }

        /**
     * Step 4.2: grouping the amazon products based on manual and fba and fbm based collecting their skus, barcode and source_product_ids
     */
    // public function groupProductsForMatching($products, $dataPrepared)
    // {
    //     $manualProducts = $fbmProducts = $fbaProducts = [];
    //     (isset($dataPrepared['sync_preference'][self::FULFILLED_BY_AMAZON])) && $matchWith[self::FULFILLED_BY_AMAZON] = $dataPrepared['sync_preference'][self::FULFILLED_BY_AMAZON];
    //     (isset($dataPrepared['sync_preference'][self::FULFILLED_BY_MERCHANT])) && $matchWith[self::FULFILLED_BY_MERCHANT] = $dataPrepared['sync_preference'][self::FULFILLED_BY_MERCHANT];

    //     /**
    //      * this will separate the amazon products based on their manual mapping, sync perefence and based on the sync preference their sku and barcodes
    //      */
    //     foreach ($products as $product) {
    //         $fulfillmentChannel = $product['fulfillment-channel'] ?? 'DEFAULT';
    //         $key = (string)($product['product-id'] . '_' . $product['seller-sku']);
    //         $isParent = false;
    //         if (isset($product['type']) && $product['type'] == 'variation') $isParent = true;

    //         if (isset($product['manual_mapped'], $product['matchedwith'], $product['source_product_id']) && $product['manual_mapped'] && ($product['matchedwith'] == self::PROCESS_MANUAL)) {
    //             /** collecting manual products */
    //             $manualProducts[$product['source_product_id']] = $product;
    //         } elseif (($fulfillmentChannel == 'DEFAULT') && isset($matchWith[self::FULFILLED_BY_MERCHANT])) {
    //             if (!empty($product['source_product_id'])) {
    //                 //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //                 $fbmProducts['source_product_id'][$key] = (string)$product['source_product_id'];
    //             } else {
    //                 if ($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_BARCODE && !$isParent) {
    //                     $fbmProducts['barcode'][$key] = (string)$product['product-id'];
    //                 } elseif (($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_SKU_PRIORITY) || ($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_BARCODE_PRIORITY)) {
    //                     !$isParent && $fbmProducts['barcode'][$key] = (string)$product['product-id'];
    //                     $fbmProducts['sku'][$key] = $product['seller-sku'];
    //                 } else {
    //                     $fbmProducts['sku'][$key] = $product['seller-sku'];
    //                 }
    //             }
    //         } elseif (($fulfillmentChannel !== 'DEFAULT') && isset($matchWith[self::FULFILLED_BY_AMAZON])) {
    //             if (!empty($product['source_product_id'])) {
    //                 //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //                 $fbaProducts['source_product_id'][$key] = (string)$product['source_product_id'];
    //             } else {
    //                 if ($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_BARCODE && !$isParent) {
    //                     $fbaProducts['barcode'][$key] = (string)$product['product-id'];
    //                 } elseif (($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_SKU_PRIORITY) || ($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_BARCODE_PRIORITY)) {
    //                     !$isParent && $fbaProducts['barcode'][$key] = (string)$product['product-id'];
    //                     $fbaProducts['sku'][$key] = $product['seller-sku'];
    //                 } else {
    //                     $fbaProducts['sku'][$key] = $product['seller-sku'];
    //                 }
    //             }
    //         }
    //     }
    //     return [
    //         'manual' => $manualProducts,
    //         'fba' => $fbaProducts,
    //         'fbm' => $fbmProducts
    //     ];
    // }


    /**
     * Step 4.2: grouping the amazon products based on manual and fba and fbm based collecting their skus, barcode and source_product_ids
     */
    public function groupProductsForMatching($products, $dataPrepared)
    {
        $matchedProducts = $fbmProducts = $fbaProducts = [];
        (isset($dataPrepared['sync_preference'][self::FULFILLED_BY_AMAZON])) && $matchWith[self::FULFILLED_BY_AMAZON] = $dataPrepared['sync_preference'][self::FULFILLED_BY_AMAZON];
        (isset($dataPrepared['sync_preference'][self::FULFILLED_BY_MERCHANT])) && $matchWith[self::FULFILLED_BY_MERCHANT] = $dataPrepared['sync_preference'][self::FULFILLED_BY_MERCHANT];
        /**
         * this will separate the amazon products based on their manual mapping, sync perefence and based on the sync preference their sku and barcodes
         */
        foreach ($products as $product) {
            $fulfillmentChannel = $product['fulfillment-channel'] ?? 'DEFAULT';
            $key = (string)($product['product-id'] . '_' . $product['seller-sku']);
            $isParent = false;
            if (isset($product['type']) && $product['type'] == 'variation') $isParent = true;

            if (isset($product['matched'], $product['matchedProduct'], $product['source_product_id']) && $product['matched'] && !isset($product['automate_status_update'])) {
                /** collecting Linked products */
                $matchedProducts[$product['source_product_id']] = $product;
            } elseif (($fulfillmentChannel == 'DEFAULT') && isset($matchWith[self::FULFILLED_BY_MERCHANT])) {

                if ($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_BARCODE && !$isParent) {
                    $fbmProducts['barcode'][$key] = (string)$product['product-id'];
                } elseif (($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_SKU_PRIORITY) || ($matchWith[self::FULFILLED_BY_MERCHANT] == self::MATCH_WITH_BARCODE_PRIORITY)) {
                    !$isParent && $fbmProducts['barcode'][$key] = (string)$product['product-id'];
                    $fbmProducts['sku'][$key] = $product['seller-sku'];
                } else {
                    $fbmProducts['sku'][$key] = $product['seller-sku'];
                }
            } elseif (($fulfillmentChannel !== 'DEFAULT') && isset($matchWith[self::FULFILLED_BY_AMAZON])) {

                if ($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_BARCODE && !$isParent) {
                    $fbaProducts['barcode'][$key] = (string)$product['product-id'];
                } elseif (($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_SKU_PRIORITY) || ($matchWith[self::FULFILLED_BY_AMAZON] == self::MATCH_WITH_BARCODE_PRIORITY)) {
                    !$isParent && $fbaProducts['barcode'][$key] = (string)$product['product-id'];
                    $fbaProducts['sku'][$key] = $product['seller-sku'];
                } else {
                    $fbaProducts['sku'][$key] = $product['seller-sku'];
                }
            }
        }
        return [
            'matchedProduct' => $matchedProducts,
            'fba' => $fbaProducts,
            'fbm' => $fbmProducts
        ];
    }

    /**
     * Step: 4.3: to update status of linked products of amazon listing in the product container
     */
    public function processManualProductsToUpdateStatus($manualLinkedAmzProducts)
    {
        try {
            $sourceProductsIds = array_map('strval', array_keys($manualLinkedAmzProducts));
            $pipeline = [
                [
                    '$match' => [
                        'user_id' => $this->userId,
                        'source_shop_id' => $this->sourceShopId,
                        'target_shop_id' => $this->targetShopId,
                        'items.source_product_id' => ['$in' => $sourceProductsIds]
                    ]
                ],
                [
                    '$unwind' => '$items'
                ]
            ];
            $refineContainer = $this->baseMongo->getCollectionForTable('refine_product');
            $refineProducts = $refineContainer->aggregate($pipeline, self::TYPEMAP_OPTIONS)->toArray();
            if (!empty($refineProducts)) {
                $bulkUpdateManualData = [];
                foreach ($refineProducts as $refineProduct) {
                    if (isset($refineProduct['items']['source_product_id']) && (isset($manualLinkedAmzProducts[$refineProduct['items']['source_product_id']]))) {
                        $listing = $manualLinkedAmzProducts[$refineProduct['items']['source_product_id']];
                        if (
                            isset($refineProduct['items']['status'])
                            && ($refineProduct['items']['status'] != $listing['status'])
                            && ($refineProduct['container_id'] ==  $listing['source_container_id'])||isset($refineProduct['items']['fulfillment_type']) &&($refineProduct['items']['fulfillment_type']!= $listing['fulfillment-channel']) ||isset($refineProduct['items']['amazonStatus']) &&($refineProduct['items']['amazonStatus']!= $listing['amazonStatus'])
                        ) {
                            if (isset($listing['fulfillment-channel']) && (strtoupper($listing['fulfillment-channel']) != 'DEFAULT')) {
                                $channel = 'FBA';
                            } else {
                                $channel = 'FBM';
                            }
                            $asinData = [
                                'source_product_id' => $listing['source_product_id'],
                                'container_id' => $listing['source_container_id'],
                                'shop_id' => (string)$this->targetShopId,
                                'status' => $listing['status'],
                                'amazonStatus'=> $listing['amazonStatus'] ?? '',
                                'target_marketplace' => 'amazon',
                                'source_shop_id' => (string)$this->sourceShopId,
                                'fulfillment_type' => $channel
                            ];
                            $bulkUpdateManualData[] = $asinData;
                        }
                    }
                }
                if (!empty($bulkUpdateManualData)) {
                    $params['target']['shopId'] = $this->targetShopId;
                    $params['source']['shopId'] = $this->sourceShopId;
                    $params['target']['marketplace'] = $this->targetMarketplace;
                    $params['source']['marketplace'] = $this->sourceMarketplace;
                    $params['user_id'] = $this->userId;
                    $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($bulkUpdateManualData);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->addExceptionLog('exception in processManualProductsToUpdateStatus: '.json_encode($e->getMessage()));
            throw $e;
        }
        return false;
    }

    // public function processProductsToUpdateStatusAndLinking($groupedSkusBarcode, $amazonProducts, $syncPreference)
    // {
    //     if (empty($amazonProducts)) return [];
    //     $sourceProductIds = $groupedSkusBarcode['source_product_id'] ?? [];
    //     $skusToProcess = $groupedSkusBarcode['sku'] ?? [];
    //     $barcodesToProcess = $groupedSkusBarcode['barcode'] ?? [];
    //     $syncInventoryIds = [];
    //     /** if the priority is sku_barcode or barcode_sku we will process this */
    //     if ($syncPreference == self::MATCH_WITH_BARCODE_PRIORITY || $syncPreference == self::MATCH_WITH_SKU_PRIORITY) {
    //         $processBarcodeFirst = false;
    //         if ($syncPreference == self::MATCH_WITH_BARCODE_PRIORITY) {
    //             $processBarcodeFirst = true;
    //         }
    //         /** to match all products first with barcode then with sku */
    //         if ($processBarcodeFirst) {
    //             /** first we will process are products with source_product_ids since it will run faster to query refine products as index utilization works*/
    //             if (!empty($sourceProductIds)) {
    //                 //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //                 $response = $this->updateStatusAndLinkProducts($sourceProductIds, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference, true);
    //                 !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //                 $processedSourceIds = $response['processedValues'] ?? [];
    //                 $leftSourceIds = $sourceProductIds;
    //                 if (!empty($processedSourceIds)) {
    //                     foreach ($amazonProducts as $amazonProduct) {
    //                         if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                             unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                         }
    //                     }
    //                 }
    //                 if (!empty($leftSourceIds)) {
    //                     $response = $this->updateStatusAndLinkProducts($leftSourceIds, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference, true);
    //                     !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //                     $processedSourceIds = $response['processedValues'] ?? [];
    //                     if (!empty($processedSourceIds)) {
    //                         foreach ($amazonProducts as $amazonProduct) {
    //                             if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                                 unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                             }
    //                         }
    //                     }
    //                     // $unprocessedSourceIds = $leftSourceIds; //these are those source_product_ids which are not matched either with sku or with barcode means their sku or barcode matching is now changed
    //                     // /**
    //                     //  * what we can do - 
    //                     //  * we need to check if teh matching is swicthed means sku matchinh changed with barcode or barcode matching chaned with sku we need to update this data
    //                     //  * in case no further ids matched we need to decide whether we need to unmap them or we need to process them but changing their source_product id products
    //                     //  */
    //                     // if (!empty($unprocessedSourceIds)) {
    //                     //     $this->unmapUnProcessedIds($unprocessedSourceIds);
    //                     //     foreach ($amazonProducts as $amazonProduct) {
    //                     //         if (isset($amazonProduct['product-id']) && isset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                     //             // unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                     //             $barcodesToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['product-id'];
    //                     //             $skusToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['seller-sku'];
    //                     //         }
    //                     //     }
    //                     // }
    //                 }
    //             }
    //             $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
    //             if (!empty($response['processedValues'])) {
    //                 $barcodesProcessed = $response['processedValues'];
    //                 if (!empty($skusToProcess)) {
    //                     //since we have processed teh barcodes we need to remove the barcode's reference skus so that they cannot be processed again
    //                     foreach ($amazonProducts as $amazonProduct) {
    //                         if (isset($amazonProduct['product-id']) && isset($barcodesProcessed[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                             unset($skusToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                         }
    //                     }
    //                     //now the skus get filtered to process
    //                 }
    //             }
    //             !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //             $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
    //             !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //         } else {
    //             if (!empty($sourceProductIds)) {
    //                 //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //                 $response = $this->updateStatusAndLinkProducts($sourceProductIds, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference, true);
    //                 !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //                 $processedSourceIds = $response['processedValues'] ?? [];
    //                 $leftSourceIds = $sourceProductIds;
    //                 if (!empty($processedSourceIds)) {
    //                     foreach ($amazonProducts as $amazonProduct) {
    //                         if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                             unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                         }
    //                     }
    //                 }
    //                 if (!empty($leftSourceIds)) {
    //                     $response = $this->updateStatusAndLinkProducts($leftSourceIds, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference, true);
    //                     !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //                     $processedSourceIds = $response['processedValues'] ?? [];
    //                     if (!empty($processedSourceIds)) {
    //                         foreach ($amazonProducts as $amazonProduct) {
    //                             if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                                 unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                             }
    //                         }
    //                     }
    //                     // $unprocessedSourceIds = $leftSourceIds; //these are those source_product_ids which are not matched either with sku or with barcode means their sku or barcode matching is now changed
    //                     // /**
    //                     //  * what we can do - 
    //                     //  * we need to check if teh matching is swicthed means sku matchinh changed with barcode or barcode matching chaned with sku we need to update this data
    //                     //  * in case no further ids matched we need to decide whether we need to unmap them or we need to process them but changing their source_product id products
    //                     //  */
    //                     // if (!empty($unprocessedSourceIds)) {
    //                     //     $this->unmapUnProcessedIds($unprocessedSourceIds);
    //                     //     foreach ($amazonProducts as $amazonProduct) {
    //                     //         if (isset($amazonProduct['product-id']) && isset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                     //             // unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                     //             $barcodesToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['product-id'];
    //                     //             $skusToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['seller-sku'];
    //                     //         }
    //                     //     }
    //                     // }
    //                 }
    //             }
    //             $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
    //             if (!empty($response['processedValues'])) {
    //                 $skusProcessed = $response['processedValues'];
    //                 if (!empty($barcodesToProcess)) {
    //                     //since we have processed teh barcodes we need to remove the barcode's reference skus so that they cannot be processed again
    //                     foreach ($amazonProducts as $amazonProduct) {
    //                         if (isset($amazonProduct['product-id']) && isset($skusProcessed[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                             unset($barcodesToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //                         }
    //                     }
    //                     //now the skus get filtered to process
    //                 }
    //             }
    //             !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //             $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
    //             !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //         }
    //     }
    //     if ($syncPreference == self::MATCH_WITH_BARCODE) {
    //         if (!empty($sourceProductIds)) {
    //             //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //             $response = $this->updateStatusAndLinkProducts($sourceProductIds, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference, true);
    //             !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //             // $processedSourceIds = $response['processedValues'] ?? [];
    //             // if (!empty($processedSourceIds)) {
    //             //     $unprocessedSourceIds = $sourceProductIds;
    //             //     foreach ($amazonProducts as $amazonProduct) {
    //             //         if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //             //             unset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //             //         }
    //             //     }
    //             //     if (!empty($unprocessedSourceIds)) {
    //             //         $this->unmapUnProcessedIds($unprocessedSourceIds);
    //             //         foreach ($amazonProducts as $amazonProduct) {
    //             //             if (isset($amazonProduct['product-id']) && isset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //             //                 // unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //             //                 $barcodesToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['product-id'];
    //             //             }
    //             //         }
    //             //     }
    //             // }
    //         }
    //         $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
    //         !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //     }
    //     if ($syncPreference == self::MATCH_WITH_SKU) {
    //         if (!empty($sourceProductIds)) {
    //             //jin products ko source_product_id ke basis par update krna h wo sare products amazon wale products ke sku and barcode me nhi hone chahiye kyuki ya to wo map honge ya ab nhi ho rhe honge jis case me match nhi ho rhe honge hme kuch nhi krna h jitne process huye hain bas utne hi sync karane hain
    //             $response = $this->updateStatusAndLinkProducts($sourceProductIds, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference, true);
    //             !empty($response['syncProductInventory']) && $syncInventoryIds  +=  $response['syncProductInventory'];
    //             // $processedSourceIds = $response['processedValues'] ?? [];
    //             // if (!empty($processedSourceIds)) {
    //             //     $unprocessedSourceIds = $sourceProductIds;
    //             //     foreach ($amazonProducts as $amazonProduct) {
    //             //         if (isset($amazonProduct['product-id']) && isset($processedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //             //             unset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //             //         }
    //             //     }
    //             //     if (!empty($unprocessedSourceIds)) {
    //             //         $this->unmapUnProcessedIds($unprocessedSourceIds);
    //             //         foreach ($amazonProducts as $amazonProduct) {
    //             //             if (isset($amazonProduct['product-id']) && isset($unprocessedSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //             //                 // unset($leftSourceIds[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
    //             //                 $skusToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct['product-id'];
    //             //             }
    //             //         }
    //             //     }
    //             // }
    //         }
    //         $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
    //         !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
    //     }
    //     return $syncInventoryIds;
    // }

    /**
     * Step 4.4:  for auto-linking products based on their sync preference
     */
    public function processProductsToUpdateStatusAndLinking($groupedSkusBarcode, $amazonProducts, $syncPreference)
    {
        if (empty($amazonProducts)) return [];
        $skusToProcess = $groupedSkusBarcode['sku'] ?? [];
        $barcodesToProcess = $groupedSkusBarcode['barcode'] ?? [];
        $syncInventoryIds = [];
        /** if the priority is sku_barcode or barcode_sku we will process this */
        if ($syncPreference == self::MATCH_WITH_BARCODE_PRIORITY || $syncPreference == self::MATCH_WITH_SKU_PRIORITY) {
            $processBarcodeFirst = false;
            if ($syncPreference == self::MATCH_WITH_BARCODE_PRIORITY) {
                $processBarcodeFirst = true;
            }
            /** to match all products first with barcode then with sku */
            if ($processBarcodeFirst) {

                $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
                if (!empty($response['processedValues'])) {
                    $barcodesProcessed = $response['processedValues'];
                    if (!empty($skusToProcess)) {
                        //since we have processed teh barcodes we need to remove the barcode's reference skus so that they cannot be processed again
                        foreach ($amazonProducts as $amazonProduct) {
                            if (isset($amazonProduct['product-id']) && isset($barcodesProcessed[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
                                unset($skusToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
                            }
                        }
                        //now the skus get filtered to process
                    }
                }
                !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
                $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
                !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
            } else {
                $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
                if (!empty($response['processedValues'])) {
                    $skusProcessed = $response['processedValues'];
                    if (!empty($barcodesToProcess)) {
                        //since we have processed teh barcodes we need to remove the barcode's reference skus so that they cannot be processed again
                        foreach ($amazonProducts as $amazonProduct) {
                            if (isset($amazonProduct['product-id']) && isset($skusProcessed[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
                                unset($barcodesToProcess[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']]);
                            }
                        }
                        //now the skus get filtered to process
                    }
                }
                !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
                $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
                !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
            }
        }
        if ($syncPreference == self::MATCH_WITH_BARCODE) {
            $response = $this->updateStatusAndLinkProducts($barcodesToProcess, $amazonProducts, self::MATCH_WITH_BARCODE, $syncPreference);
            !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
        }
        if ($syncPreference == self::MATCH_WITH_SKU) {
            $response = $this->updateStatusAndLinkProducts($skusToProcess, $amazonProducts, self::MATCH_WITH_SKU, $syncPreference);
            !empty($response['syncProductInventory']) && $syncInventoryIds += $response['syncProductInventory'];
        }
        return $syncInventoryIds;
    }

    public function unmapUnProcessedIds($unprocessSourceIds)
    {
        $manualUnmapParams = [
            'data' => [
                'user_id' => $this->userId,
                'syncStatus' => true,
            ],
            'source' => [
                'shopId' => $this->sourceShopId,
                'marketplace' => $this->sourceMarketplace
            ],
            'target' => [
                'shopId' => $this->targetShopId,
                'marketplace' => $this->targetMarketplace
            ]
        ];
        $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
        foreach($unprocessSourceIds as $key => $sourceProductId) {
            $manualUnmapParams['data']['source_product_id'] = (string)$sourceProductId;
            $productHelper->manualUnmap($manualUnmapParams);
        }
        return true;
    }

    public function getRefineProductsBasedOnValues($matchWithValues, $matchWith, $searchBasedOnSourceProductIds)
    {
        try {
            $matchWithValues = array_values($matchWithValues);
            $matchWithValues = array_map('strval', $matchWithValues); //need to check once if sku and barcode are saved in string or not
            $matchQuery = [
                'user_id' => $this->userId,
                'source_shop_id' => $this->sourceShopId,
                'target_shop_id' => $this->targetShopId
            ];
    
            if ($searchBasedOnSourceProductIds) {
                $matchQuery['items.source_product_id'] = ['$in' => $matchWithValues];
            } elseif ($matchWith == 'barcode') {
                $matchQuery['items.barcode'] = ['$in' => $matchWithValues];
            } elseif ($matchWith == 'sku') {
                $matchQuery['items.sku'] = ['$in' => $matchWithValues];
            }
            $pipeline = [
                [
                    '$match' => $matchQuery
                ],
                [
                    '$unwind' => '$items'
                ]
            ];
            $refineContainer = $this->baseMongo->getCollectionForTable('refine_product');
            return $refineContainer->aggregate($pipeline, self::TYPEMAP_OPTIONS)->toArray();
        } catch (Exception $e) {
            $this->addExceptionLog('exception in getRefineProductsBasedOnValues: '.json_encode($e->getMessage()));
        }
        return [];
    }

    public function getManualMappedListings($refineProducts)
    {
        try {
            $alreadyLinkedProductListingsIds = [];
            $manualMappedListings = [];
            foreach ($refineProducts as $refineProduct) {
                //we need to check if the product is having target_listing_id and is manually linked since refine has no definition for manual products
                if (!empty($refineProduct['items']['target_listing_id']) && (is_string($refineProduct['items']['target_listing_id']) || is_object($refineProduct['items']['target_listing_id']))) {
                    $alreadyLinkedProductListingsIds[(string)$refineProduct['items']['source_product_id']] = is_string($refineProduct['items']['target_listing_id']) ? new ObjectId((string)$refineProduct['items']['target_listing_id']) : $refineProduct['items']['target_listing_id'];
                }
            }
            if (!empty($alreadyLinkedProductListingsIds)) {
                $listingIds = array_values($alreadyLinkedProductListingsIds);
                $query = [
                    '_id' => ['$in' => $listingIds],
                    'user_id' => (string)$this->userId,
                    'shop_id' => (string)$this->targetShopId,
                    'manual_mapped' => true,
                    'matchedwith' => self::PROCESS_MANUAL
                ];
                // $this->addLog('getManualMappedListings: '.json_encode($query));
                $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
                $manualLinkedIds = $amazonCollection->distinct('_id', $query);
                if (!empty($manualLinkedIds)) {
                    $linkedIdMap = array_flip(array_map('strval', $manualLinkedIds)); // Faster lookup
                    foreach ($alreadyLinkedProductListingsIds as $sourcePId => $listingId) {
                        if (isset($linkedIdMap[(string)$listingId])) {
                            $manualMappedListings[(string)$sourcePId] = $listingId;
                        }
                    }
                }
            }
            // $this->addLog('manualMappedListings: '.json_encode($manualMappedListings));
            return $manualMappedListings;
        } catch (Exception $e) {
            $this->addExceptionLog('exception in getManualMappedListings: '.json_encode($e->getMessage()));
        }
        return false;
    }

    // public function updateStatusAndLinkProducts($matchWithValues, $amazonProducts, $matchWith = 'sku', $syncPreference = "sku", $searchBasedOnSourceProductIds = false)
    // {
    //     try {
    //         if (empty($matchWithValues) || !in_array($matchWith, ['sku', 'barcode'])) {
    //             return [
    //                 'processedValues' => [],
    //                 'syncProductInventory' => []
    //             ];
    //         }
    //         $objectManager = $this->di->getObjectManager();
    //         $productHelper = $objectManager->get(ProductHelper::class);
    //         $validator = $objectManager->get(SourceModel::class);

    //         $manualUnmapParams = [
    //             'data' => [
    //                 'user_id' => $this->userId,
    //                 'syncStatus' => true,
    //             ],
    //             'source' => [
    //                 'shopId' => $this->sourceShopId,
    //                 'marketplace' => $this->sourceMarketplace
    //             ],
    //             'target' => [
    //                 'shopId' => $this->targetShopId,
    //                 'marketplace' => $this->targetMarketplace
    //             ]
    //         ];

    //         $refineProducts = $this->getRefineProductsBasedOnValues($matchWithValues, $matchWith, $searchBasedOnSourceProductIds);
    //         $processedValues = [];
    //         $syncProductInventory = [];
    //         $processedSourceProductIds = [];
    //         if (!empty($refineProducts)) {
    //             $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
    //             $manualMappedListings = $this->getManualMappedListings($refineProducts);
    //             $bulkupdateasinData = [];
    //             $bulkUpdateAmazonData = [];
    //             $skipSourceIds = [];
    //             foreach ($amazonProducts as $amazonProduct) {
    //                 if (isset($amazonProduct['status']) && $amazonProduct['status'] == 'Deleted') {
    //                     //unset barcode if exist in this case so that it cannot be processed
    //                     /** do not link / unlink products identify deleted on amazon */
    //                     continue;
    //                 }
    //                 if (isset($amazonProduct['manual_mapped']) && $amazonProduct['manual_mapped']) {
    //                     /** manually mapped products need not to be processed in this code*/
    //                     continue;
    //                 }
    //                 if (!empty($processedValues) && isset($processedValues[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
    //                     /** manually mapped products need not to be processed in this code*/
    //                     continue;
    //                 }
    //                 // a parent product with sku should only match with a parent product of the shopify only
    //                 $isParent = false;
    //                 if (isset($amazonProduct['type']) && $amazonProduct['type'] == 'variation') {
    //                     $isParent = true;
    //                 }
    //                 $fulfillmentChannel = $amazonProduct['fulfillment-channel'] ?? 'DEFAULT';
    //                 if ($fulfillmentChannel == 'DEFAULT') {
    //                     $channel = self::FULFILLED_BY_MERCHANT;
    //                 } else {
    //                     $channel = self::FULFILLED_BY_AMAZON;
    //                 }

    //                 $sourceProduct = [];
    //                 if ($matchWith == 'barcode') {
    //                     $filterKey = 'product-id';
    //                 } elseif ($matchWith == 'sku') {
    //                     $filterKey = 'seller-sku';
    //                 }
    //                 foreach ($refineProducts as $refineProduct) {
    //                     if (!empty($refineProduct['items']['source_product_id']) && array_key_exists($refineProduct['items']['source_product_id'], $processedSourceProductIds)) {
    //                         //since the product is already processed
    //                         continue;
    //                     }
    //                     if (!empty($manualMappedListings) && isset($refineProduct['items']['source_product_id']) && isset($manualMappedListings[(string)$refineProduct['items']['source_product_id']])) {
    //                         //since product is manually mapped cannot perform auto linking
    //                         continue;
    //                     }
    //                     if ($searchBasedOnSourceProductIds && ($refineProduct['items']['source_product_id'] == $amazonProduct['source_product_id'])) {
    //                         if ($refineProduct['items'][$matchWith] == $amazonProduct[$filterKey]) {
    //                             if(($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] == 'variation') && $isParent && $matchWith == self::MATCH_WITH_SKU) {
    //                                 $sourceProduct[] = $refineProduct;
    //                                 break;
    //                             } elseif(($refineProduct['items']['source_product_id'] != $refineProduct['container_id']) && ($refineProduct['type'] == 'simple') && !$isParent) {
    //                                 $sourceProduct[] = $refineProduct;
    //                                 break;
    //                             }  elseif (($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] != 'simple')) {
    //                                 //parent product cannot be linked
    //                                 continue;
    //                             } elseif (!$isParent) {
    //                                 $sourceProduct[] = $refineProduct;
    //                                 break;
    //                             }
    //                         } else {
    //                             $skipSourceIds[(string)$amazonProduct['source_product_id']] = (string)$amazonProduct['source_product_id']; //so that we cannot process them when we are processing via sku or barcode so that they cannot be unmapped
    //                             continue;
    //                         }
    //                     } elseif ($refineProduct['items'][$matchWith] == $amazonProduct[$filterKey]) {
    //                         //agar hm source product id ke basis par search kr rhe hain aur hme source_product_id par product mil rha h but sync preference ke basis par match nhi kr rha h to usko sync nhi kareneges
    //                         if (($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] == 'variation') && $isParent && $matchWith == self::MATCH_WITH_SKU) {
    //                             $sourceProduct[] = $refineProduct;
    //                             break;
    //                         } elseif(($refineProduct['items']['source_product_id'] != $refineProduct['container_id']) && ($refineProduct['type'] == 'simple') && !$isParent) {
    //                             $sourceProduct[] = $refineProduct;
    //                             break;
    //                         } elseif (($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] != 'simple')) {
    //                             //parent product cannot be linked
    //                             continue;
    //                         } elseif (!$isParent) {
    //                             $sourceProduct[] = $refineProduct;
    //                         }
    //                     }
    //                 }
    //                 if (count($sourceProduct) > 1) {
    //                     $products = $sourceProduct;
    //                     $sourceProduct = [];
    //                     $sourceProduct[] = $products[0];
    //                 }
    //                 if (!empty($sourceProduct) && (count($sourceProduct) == 1)) {
    //                     $sourceProduct = $sourceProduct[0] ?? $sourceProduct;
    //                     $status = $amazonProduct['status'];
    //                     if ($isParent) {
    //                         $status = 'parent_' . $status;
    //                     }
    //                     if (
    //                         isset($amazonProduct['source_product_id']) && ($amazonProduct['source_product_id'] == $sourceProduct['items']['source_product_id'])
    //                         && isset($sourceProduct['items']['target_listing_id']) && ($sourceProduct['items']['target_listing_id'] == (string)$amazonProduct['_id'])
    //                     ) {
    //                         if ($sourceProduct['items']['status'] != $amazonProduct['status']) {
    //                             //here we also need to update teh code based on this case as well that the matching is now being done on the basis of different preference so we need to update the matchwith key's value as well in case of change
    //                             //check and update status if needed in already mapped product
    //                             $asinData = [
    //                                 'source_product_id' => $sourceProduct['items']['source_product_id'],
    //                                 'container_id' => $sourceProduct['container_id'],
    //                                 'shop_id' => $this->targetShopId,
    //                                 'source_shop_id' => $this->sourceShopId,
    //                                 'target_marketplace' => $this->targetMarketplace,
    //                                 'status' => $status,
    //                                 'fulfillment_channel_code' => $fulfillmentChannel,
    //                                 'fulfillment_type' => $channel
    //                             ];
    //                             // Commenting it because due to the below code keys inside matchedProduct is getting unset
    //                             // if (isset($amazonProduct['matchedwith']) && ($amazonProduct['matchedwith'] !== $matchWith)) {
    //                             //     if ($sourceProduct['items'][$matchWith] == $amazonProduct[$filterKey]) {
    //                             //             $matchedData = [
    //                             //                 'matched' => true,
    //                             //                 'matchedwith' => $matchWith,
    //                             //                 'matchedProduct' => [
    //                             //                     'sku' => $sourceProduct['items']['sku']  ?? '',
    //                             //                     'matchedwith' => $matchWith,
    //                             //                     'barcode' => $sourceProduct['items']['barcode'] ?? '',
    //                             //                 ],
    //                             //                 'matched_updated_at' => date('c')
    //                             //             ];
    //                             //             $bulkUpdateAmazonData[] = [
    //                             //                 'updateOne' => [
    //                             //                     ['_id' => $amazonProduct['_id']],
    //                             //                     ['$set' => $matchedData, '$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1]]
    //                             //                 ],
    //                             //             ];
    //                             //     }
    //                             // }
    //                             $bulkupdateasinData[] = $asinData;
    //                             $processedValues[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct[$filterKey];
    //                             $processedSourceProductIds[$sourceProduct['items']['source_product_id']] =  $sourceProduct['items']['source_product_id'];
    //                         }
    //                     } elseif (empty($sourceProduct['items']['target_listing_id']) && empty($amazonProduct['source_product_id'])) {
    //                         //when lookup true skip because at that time we assume that there will be no product which need to be unmapped
    //                         // if (!$this->lookup) {
    //                         //     /**
    //                         //      * there is a case- suppose we have a matching preference of barcode_sku and we have 2 amazon products with A barcode and B sku 
    //                         //      * now suppose when first we match the product with barcode A it gets matched with the shopify product having barcode A and sku B since the priority was of barcode it firsts get mapped
    //                         //      * now when we process sku B of amazon the same shopify product will be picked again but this time it need to be mapped with the sku
    //                         //      * then what we do is we unlink it's mapping with barcode and link it with sku instead of teh fact that the priority is of barcode
    //                         //      * */
    //                         //     $manualUnmapParams['data']['source_product_id'] = $sourceProduct['items']['source_product_id'];
    //                         //     $productHelper->manualUnmap($manualUnmapParams); //we also need to make sure that this was linked to some product and that product is not the manaully mapped once else we need to immeditaley skip the prodduct matching for this one
    //                         // }
    //                         //
    //                         $asin = $amazonProduct['asin1'] ?? $amazonProduct['asin2'] ?? $amazonProduct['asin3'] ?? '';
    //                         $asinData = [
    //                             'shop_id' => $this->targetShopId,
    //                             'source_product_id' => $sourceProduct['items']['source_product_id'],
    //                             'container_id' => $sourceProduct['container_id'],
    //                             'target_marketplace' => $this->targetMarketplace,
    //                             'source_shop_id' => $this->sourceShopId,
    //                             'sku' => $amazonProduct['seller-sku'], //why we only replacing the amazon's sku what if the matching was being done on the basis of barcode
    //                             'shopify_sku' => $sourceProduct['items']['sku'],
    //                             'status' => $status,
    //                             'asin' => $asin,
    //                             'fulfillment_type' => $channel,
    //                             'fulfillment_channel_code' => $fulfillmentChannel,
    //                             'matched_at' => date('c'),
    //                             'target_listing_id' => (string)$amazonProduct['_id'],
    //                         ];
    //                         //this is done to verify that we are not saving any duplicate amazon sku
    //                         $valid = $validator->validateSaveProduct([$asinData], false, $manualUnmapParams);
    //                         if (isset($valid['success']) && !$valid['success']) continue;

    //                         /** this is done so that inventory sync should only be run for the freshly linked products only */
    //                         if (!$isParent && isset($sourceProduct['items']['target_listing_id']) && ($sourceProduct['items']['target_listing_id'] != (string)$amazonProduct['_id'])) {
    //                             $syncProductInventory[$sourceProduct['items']['source_product_id']] = $sourceProduct['items']['source_product_id'];
    //                         } elseif (!isset($sourceProduct['items']['target_listing_id'])) {
    //                             $syncProductInventory[$sourceProduct['items']['source_product_id']] = $sourceProduct['items']['source_product_id'];
    //                         }

    //                         $bulkupdateasinData[] = $asinData;
    //                         // if (isset($amazonProduct['source_product_id']) && ($amazonProduct['source_product_id'] != $sourceProduct['items']['source_product_id'])) {
    //                         //     $manualUnmapParams['data']['source_product_id'] = $amazonProduct['source_product_id'];
    //                         //     $productHelper->manualUnmap($manualUnmapParams);
    //                         // }
    //                         $matchedData = [
    //                             'matched' => true,
    //                             'matchedwith' => $matchWith,
    //                             'matchedProduct' => [
    //                                 'source_product_id' => $sourceProduct['items']['source_product_id'],
    //                                 'container_id' => $sourceProduct['container_id'],
    //                                 'shop_id' => $this->sourceShopId,
    //                                 'sku' => $sourceProduct['items']['sku']  ?? '',
    //                                 'matchedwith' => $matchWith,
    //                                 'title' => $sourceProduct['items']['title'] ?? '',
    //                                 'barcode' => $sourceProduct['items']['barcode'] ?? '',
    //                                 'main_image' => $sourceProduct['items']['main_image'] ?? ''
    //                             ],
    //                             'source_product_id' => $sourceProduct['items']['source_product_id'],
    //                             'source_container_id' => $sourceProduct['container_id'],
    //                             'matched_updated_at' => date('c'),
    //                             'matched_at' => date('c')
    //                         ];
    //                         $bulkUpdateAmazonData[] = [
    //                             'updateOne' => [
    //                                 ['_id' => $amazonProduct['_id']],
    //                                 ['$set' => $matchedData, '$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1]]
    //                             ],
    //                         ];
    //                         $processedValues[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct[$filterKey];
    //                         $processedSourceProductIds[$sourceProduct['items']['source_product_id']] =  $sourceProduct['items']['source_product_id'];
    //                     }
    //                     //we have covered up teh cases of matching with barcode and sku on single product but the case of multiple product which is close matching is not handled as per dicussion done with Amee sir we will not continue but the 3and 4 cases of this in which we are setting and removing the close matching we need to handle this after dicussion
    //                 } else {
    //                     //for the case if product is no more exist we need to unlink the amazon product
    //                     //there is one more case - it is also possible that the product we are going to unmap is actually now has been changed from their listing and it is now being matched with barcode otehr than teh sku
    //                     //reason behind using skipSourceIds is this that the product's matching is now changed and it is now being not matching with the sku or teh barcode but the vice versa so we cannot change it
    //                     // if (empty($sourceProduct) && isset($amazonProduct['source_product_id']) && !isset($skipSourceIds[(string)$amazonProduct['source_product_id']])) {
    //                     //     $manualUnmapParams['data']['source_product_id'] = $amazonProduct['source_product_id'];
    //                     //     $productHelper->manualUnmap($manualUnmapParams);
    //                     // }
    //                 }
    //             }
    //             if (!empty($bulkupdateasinData)) {
    //                 $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($bulkupdateasinData, false, $manualUnmapParams);
    //             }
    //             if (!empty($bulkUpdateAmazonData)) {
    //                 $amazonCollection->BulkWrite($bulkUpdateAmazonData, ['w' => 1]);
    //             }
    //         }
    //         return [
    //             'processedValues' => $processedValues,
    //             'syncProductInventory' => $syncProductInventory
    //         ];
    //     } catch (Exception $e) {
    //         $this->addExceptionLog('Exception during updateStatusAndLinkProducts: '.json_encode($e->getMessage()));
    //         return [
    //             'processedValues' => [],
    //             'syncProductInventory' => []
    //         ];
    //     }
    // }

    /**
     * Step 4.5:  for autolinking products based on the matchWith key
     */
    public function updateStatusAndLinkProducts($matchWithValues, $amazonProducts, $matchWith = 'sku', $syncPreference = "sku", $searchBasedOnSourceProductIds = false)
    {
        try {
            if (empty($matchWithValues) || !in_array($matchWith, ['sku', 'barcode'])) {
                return [
                    'processedValues' => [],
                    'syncProductInventory' => []
                ];
            }
            $objectManager = $this->di->getObjectManager();
            $productHelper = $objectManager->get(ProductHelper::class);
            $validator = $objectManager->get(SourceModel::class);

            $manualUnmapParams = [
                'data' => [
                    'user_id' => $this->userId,
                    'syncStatus' => true,
                ],
                'source' => [
                    'shopId' => $this->sourceShopId,
                    'marketplace' => $this->sourceMarketplace
                ],
                'target' => [
                    'shopId' => $this->targetShopId,
                    'marketplace' => $this->targetMarketplace
                ]
            ];

            $refineProducts = $this->getRefineProductsBasedOnValues($matchWithValues, $matchWith, $searchBasedOnSourceProductIds);
            $processedValues = [];
            $syncProductInventory = [];
            $processedSourceProductIds = [];
            if (!empty($refineProducts)) {
                $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
                // $manualMappedListings = $this->getManualMappedListings($refineProducts);
                $bulkupdateasinData = [];
                $bulkUpdateAmazonData = [];
                foreach ($amazonProducts as $amazonProduct) {
                    if (isset($amazonProduct['status']) && $amazonProduct['status'] == 'Deleted') {
                        //unset barcode if exist in this case so that it cannot be processed
                        /** do not link / unlink products identify deleted on amazon */
                        continue;
                    }
                    if(isset($amazonProduct['automate_sync']) && $amazonProduct['automate_sync']==false) {
                        /** automate sync false products need not to be processed in this code*/
                        continue;
                    }
                    if (isset($amazonProduct['matched'], $amazonProduct['matchedProduct'] , $amazonProduct['source_product_id']) && $amazonProduct['matched']) {
                        /**  mapped products need not to be processed in this code*/
                        continue;
                    }
                    if (!empty($processedValues) && isset($processedValues[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']])) {
                        /** manually mapped products need not to be processed in this code*/
                        continue;
                    }
                    // a parent product with sku should only match with a parent product of the shopify only
                    $isParent = false;
                    if (isset($amazonProduct['type']) && $amazonProduct['type'] == 'variation') {
                        $isParent = true;
                    }
                    $fulfillmentChannel = $amazonProduct['fulfillment-channel'] ?? 'DEFAULT';
                    if ($fulfillmentChannel == 'DEFAULT') {
                        $channel = self::FULFILLED_BY_MERCHANT;
                    } else {
                        $channel = self::FULFILLED_BY_AMAZON;
                    }

                    $sourceProduct = [];
                    if ($matchWith == 'barcode') {
                        $filterKey = 'product-id';
                    } elseif ($matchWith == 'sku') {
                        $filterKey = 'seller-sku';
                    }
                    foreach ($refineProducts as $refineProduct) {
                        if (!empty($refineProduct['items']['source_product_id']) && array_key_exists($refineProduct['items']['source_product_id'], $processedSourceProductIds)) {
                            //since the product is already processed
                            continue;
                        }
                        if (isset($refineProduct['items']['status']) && isset($refineProduct['items']['target_listing_id'])) {
                            //since product is  already linked cannot perform auto linking
                            continue;
                        }
                        if ($refineProduct['items'][$matchWith] == $amazonProduct[$filterKey]) {
                            if (($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] == 'variation') && $isParent && $matchWith == self::MATCH_WITH_SKU) {
                                $sourceProduct[] = $refineProduct;
                                break;
                            } elseif(($refineProduct['items']['source_product_id'] != $refineProduct['container_id']) && ($refineProduct['type'] == 'simple') && !$isParent) {
                                $sourceProduct[] = $refineProduct;
                                break;
                            } elseif (($refineProduct['items']['source_product_id'] == $refineProduct['container_id']) && ($refineProduct['type'] != 'simple')) {
                                //parent product cannot be linked
                                continue;
                            } elseif (!$isParent) {
                                $sourceProduct[] = $refineProduct;
                            }
                        }
                    }
                    if (count($sourceProduct) > 1) {
                        $products = $sourceProduct;
                        $sourceProduct = [];
                        $sourceProduct[] = $products[0];
                    }
                    if (!empty($sourceProduct) && (count($sourceProduct) == 1)) {
                        $sourceProduct = $sourceProduct[0] ?? $sourceProduct;
                        $status = $amazonProduct['status'];
                        if ($isParent) {
                            $status = 'parent_' . $status;
                        }
                        if (empty($sourceProduct['items']['target_listing_id']) && empty($amazonProduct['source_product_id'])) {
                            $asin = $amazonProduct['asin1'] ?? $amazonProduct['asin2'] ?? $amazonProduct['asin3'] ?? '';
                            $asinData = [
                                'shop_id' => $this->targetShopId,
                                'source_product_id' => $sourceProduct['items']['source_product_id'],
                                'container_id' => $sourceProduct['container_id'],
                                'target_marketplace' => $this->targetMarketplace,
                                'source_shop_id' => $this->sourceShopId,
                                'sku' => $amazonProduct['seller-sku'], //why we only replacing the amazon's sku what if the matching was being done on the basis of barcode
                                'shopify_sku' => $sourceProduct['items']['sku'],
                                'status' => $status,
                                'asin' => $asin,
                                'amazonStatus' => $amazonProduct['amazonStatus'],
                                'fulfillment_type' => $channel,
                                'fulfillment_channel_code' => $fulfillmentChannel,
                                'matched_at' => date('c'),
                                'target_listing_id' => (string)$amazonProduct['_id'],
                            ];
                            //this is done to verify that we are not saving any duplicate amazon sku
                            $valid = $validator->validateSaveProduct([$asinData], false, $manualUnmapParams);
                            if (isset($valid['success']) && !$valid['success']) continue;

                            /** this is done so that inventory sync should only be run for the freshly linked products only */
                            if (!isset($sourceProduct['items']['target_listing_id'])) {
                                $syncProductInventory[$sourceProduct['items']['source_product_id']] = $sourceProduct['items']['source_product_id'];
                            }

                            $bulkupdateasinData[] = $asinData;

                            $matchedData = [
                                'matched' => true,
                                'matchedwith' => $matchWith,
                                'matchedProduct' => [
                                    'source_product_id' => $sourceProduct['items']['source_product_id'],
                                    'container_id' => $sourceProduct['container_id'],
                                    'shop_id' => $this->sourceShopId,
                                    'sku' => $sourceProduct['items']['sku']  ?? '',
                                    'matchedwith' => $matchWith,
                                    'title' => $sourceProduct['items']['title'] ?? '',
                                    'barcode' => $sourceProduct['items']['barcode'] ?? '',
                                    'main_image' => $sourceProduct['items']['main_image'] ?? ''
                                ],
                                'source_product_id' => $sourceProduct['items']['source_product_id'],
                                'source_container_id' => $sourceProduct['container_id'],
                                'matched_updated_at' => date('c'),
                                'matched_at' => date('c')
                            ];
                            $bulkUpdateAmazonData[] = [
                                'updateOne' => [
                                    ['_id' => $amazonProduct['_id']],
                                    ['$set' => $matchedData, '$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1]]
                                ],
                            ];
                            $processedValues[$amazonProduct['product-id'] . '_' . $amazonProduct['seller-sku']] = $amazonProduct[$filterKey];
                            $processedSourceProductIds[$sourceProduct['items']['source_product_id']] =  $sourceProduct['items']['source_product_id'];
                        }
                        //we have covered up teh cases of matching with barcode and sku on single product but the case of multiple product which is close matching is not handled as per dicussion done with Amee sir we will not continue but the 3and 4 cases of this in which we are setting and removing the close matching we need to handle this after dicussion
                    }
                }
                if (!empty($bulkupdateasinData)) {
                    $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($bulkupdateasinData, false, $manualUnmapParams);
                }
                if (!empty($bulkUpdateAmazonData)) {
                    $amazonCollection->BulkWrite($bulkUpdateAmazonData, ['w' => 1]);
                }
            }
            return [
                'processedValues' => $processedValues,
                'syncProductInventory' => $syncProductInventory
            ];
        } catch (Exception $e) {
            $this->addExceptionLog('Exception during updateStatusAndLinkProducts: '.json_encode($e->getMessage()));
            throw $e;
            return [
                'processedValues' => [],
                'syncProductInventory' => []
            ];
        }
    }

    /**
     * to process image sync
     */
    public function saveAmazonListingImage($sqsData)
    {
        try {
            $this->addLog('--------saveAmazonListingImage------');
            (string)$userId = $this->userId ?? $sqsData['user_id'];
            $failedSkus = [];
            $sellerSkus = [];
            $bulkImageUpdate = [];
            $preparedData = $sqsData['data'];
            $counter = $preparedData['counter'] ?? 0;
            (int)$limit = $preparedData['limit'] ?? self::DEFAULT_PAGINATION_IMAGE;
            $lastPointer = $preparedData['lastPointer'] ?? '';
            $amazonCollection = $this->baseMongo->getCollection(Helper::AMAZON_LISTING);
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $amazonListing = [];
            $listingCount = 0;
            $targetShopId = $preparedData['home_shop_id'];
            if (!isset($preparedData['last_page']) || !$preparedData['last_page']) {
                $filter = ['user_id' => $userId, 'shop_id' => (string)$targetShopId, 'image-url' => null, 'seller-sku' => ['$nin' => [null, '']]];
                if ($lastPointer) {
                    $filter['_id'] = ['$gt' => new ObjectId((string)$lastPointer)];
                }
                $amazonListing = $amazonCollection->find(
                    $filter,
                    [
                        'projection' => ['asin1' => 1, 'seller-sku' => 1],
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'sort' => ['_id' => 1],
                        'limit' => (int)$limit,
                    ]
                );
            }

            if (!empty($amazonListing)) {
                $amazonListing = $amazonListing->toArray();
                $listingCount = count($amazonListing);
                foreach ($amazonListing as $listing) {
                    if (!empty($listing['seller-sku'])) {
                        $sellerSkus[] = $listing['seller-sku'];
                    }
                }
            }

            // failed SKUs from previous throttled attempt
            if (isset($preparedData['failed_skus']) && is_array($preparedData['failed_skus'])) {
                $sellerSkus = array_merge($sellerSkus, $preparedData['failed_skus']);
            }

            $sellerSkus = array_unique($sellerSkus);
            if (!empty($sellerSkus)) {
                $skuBatches = array_chunk(array_values($sellerSkus), 20);
                $this->addLog('image import: ' . $listingCount . ' listings, ' . count($sellerSkus) . ' with seller-sku, ' . count($skuBatches) . ' batches');
                $isApiExhaust = false;
                $failedSkusDueToThrottle = [];
                $lastSuccessRequestTime = null;
                $lastThrottleTime = null;
                foreach ($skuBatches as $skuBatch) {
                    if ($isApiExhaust) {
                        $currentTime = microtime(true);
                        if (($currentTime - $lastThrottleTime) > 1.0) {
                            $isApiExhaust = false;
                        }
                    }
                    if (!$isApiExhaust) {
                        $params = [
                            'sku' => $skuBatch,
                            'shop_id' => (string)$preparedData['remote_shop_id'],
                            'includedData' => 'summaries,relationships,attributes',
                            'page_size' => 20,
                        ];
                        $response = $commonHelper->sendRequestToAmazon('search-listing', $params, 'GET');
                        $this->addLog('image import: sent ' . count($skuBatch) . ' SKUs, received ' . count($response['response']['items'] ?? []) . ' items, numberOfResults=' . ($response['response']['numberOfResults'] ?? 'n/a'));
                        if (isset($response['throttled']) && $response['throttled']) {
                            $lastThrottleTime = microtime(true);
                            $timeToSleep = 1;
                            if (!is_null($lastSuccessRequestTime)) {
                                $diff = ($lastThrottleTime - $lastSuccessRequestTime);
                                if ($diff < 1) {
                                    $timeToSleep = ceil(1 - $diff);
                                }
                            }
                            usleep($timeToSleep * 1000000);
                            $response = $commonHelper->sendRequestToAmazon('search-listing', $params, 'GET');
                        }
                        if (isset($response['throttled']) && $response['throttled']) {
                            $this->addLog('image import: throttled, ' . count($skuBatch) . ' SKUs deferred');
                            $failedSkusDueToThrottle = array_merge($failedSkusDueToThrottle, $skuBatch);
                            $isApiExhaust = true;
                            $lastThrottleTime = microtime(true);
                        }
                        if (!isset($response['throttled'])) {
                            $lastSuccessRequestTime = microtime(true);
                            !is_null($lastThrottleTime) && $lastThrottleTime = null;
                        }
                        if (isset($response['success']) && $response['success'] && isset($response['response']['items']) && is_array($response['response']['items'])) {
                            foreach ($response['response']['items'] as $item) {
                                $setProduct = [];
                                $itemSku = $item['sku'] ?? null;
                                if (!$itemSku) {
                                    continue;
                                }
                                // status from summaries status
                                $amazonStatus = $item['summaries'][0]['status'] ?? null;
                                if (!empty($amazonStatus) ) {
                                    $setProduct['amazonStatus'] = $amazonStatus;
                                } else {
                                    $setProduct['amazonStatus'] = [];
                                }

                                // image from summaries mainImage
                                $imageUrl = $item['summaries'][0]['mainImage']['link'] ?? null;
                                if ($imageUrl && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                    $setProduct['image-url'] = $imageUrl;
                                } else {
                                    $setProduct['image-url'] = '';
                                }

                                // parent/child/simple classification
                                $classification = $this->classifyListingItem($item);
                                $setProduct['type'] = $classification['type'];
                                $setProduct['visibility'] = $classification['visibility'];

                                $bulkImageUpdate[] = [
                                    'updateMany' => [
                                        ['user_id' => $userId, 'shop_id' => (string)$targetShopId, 'seller-sku' => $itemSku],
                                        ['$set' => $setProduct]
                                    ]
                                ];
                            }
                        } else if (!isset($response['throttled'])) {
                            $this->addLog('image import: API error, response: ' . json_encode($response['message'] ?? $response['error'] ?? 'unknown'));
                            break;
                        }
                    } else {
                        $failedSkusDueToThrottle = array_merge($failedSkusDueToThrottle, $skuBatch);
                    }
                }

                if (!empty($bulkImageUpdate)) {
                    $amazonCollection->BulkWrite($bulkImageUpdate, ['w' => 1]);
                    $this->addLog('image import: bulk write ' . count($bulkImageUpdate) . ' updates');
                }

                if ($listingCount >= $limit) {
                    $individuaWeight = $preparedData['individual_weight'];
                    $progress = 98 / $individuaWeight;
                    $preparedData['lastPointer'] = (string)end($amazonListing)['_id'];
                    $preparedData['failed_skus'] = $failedSkusDueToThrottle;
                    $sqsData['data'] = $preparedData;
                    $res = $this->updateProcess($sqsData['data']['queued_task_id'], $progress);
                    if ($res < 100) {
                        unset($sqsData['delay']);
                        $this->di->getMessageManager()->pushMessage($sqsData);
                        $this->addLog('saveAmazonListingImage $listingCount >= $limit: '.json_encode($sqsData));
                        return true;
                    }
                }
                if ($counter <= 2 && !empty($failedSkusDueToThrottle)) {
                    $preparedData['last_page'] = true;
                    $preparedData['failed_skus'] = $failedSkusDueToThrottle;
                    $counter += 1;
                    $preparedData['counter'] = $counter;
                    $sqsData['data'] = $preparedData;
                    $sqsData['delay'] = 30;
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return true;
                }
            }
            $this->addLog('image import: complete');
            $this->updateProcess($sqsData['data']['queued_task_id'], 100, 'Image(s) of Amazon listing synced successfully.', 'success', null, false);
            if (isset($sqsData['match_status_data']) && !empty($sqsData['match_status_data'])) {
                $this->initiateSyncAfterImport($sqsData['match_status_data']);
                $this->addLog('calling initiateSyncAfterImport after image import: '.json_encode($sqsData['match_status_data']));
            }
            return true;
        } catch (Exception $e) {
            $this->addLog('image import exception: ' . $e->getMessage());
            $this->di->getLog()->logContent('exception in saveAmazonListingImage user_id=' . ($userId ?? 'unknown') . ' shop_id=' . ($targetShopId ?? 'unknown') . ' error=' . $e->getMessage() . ' trace=' . $e->getTraceAsString(), 'info', 'exception.log');
            return false;
        }
    }

    /**
     * Classify a search-listing item as parent, child, or simple.
     * Parent requires double confirmation: parentage_level=parent AND childSkus in relationships.
     */
    private function classifyListingItem(array $item): array
    {
        // First, scan relationships for VARIATION childSkus/parentSkus
        $hasChildSkus = false;
        $hasParentSkus = false;
        foreach ($item['relationships'] ?? [] as $rel) {
            foreach ($rel['relationships'] ?? [] as $r) {
                if (($r['type'] ?? '') === 'VARIATION') {
                    if (!empty($r['childSkus'])) {
                        $hasChildSkus = true;
                    }
                    if (!empty($r['parentSkus'])) {
                        $hasParentSkus = true;
                    }
                }
            }
        }

        // Child detection: parentSkus in relationships → child
        if ($hasParentSkus) {
            return ['type' => 'simple', 'visibility' => 'Not Visible Individually'];
        }

        $parentageLevel = $item['attributes']['parentage_level'][0]['value'] ?? null;
        $cpsr = $item['attributes']['child_parent_sku_relationship'][0] ?? null;
        $hasFulfillmentAvailability = isset($item['attributes']['fulfillment_availability']);

        // Child detection: parentage_level or parent_sku attribute
        if ($parentageLevel === 'child' || !empty($cpsr['parent_sku'] ?? null)) {
            return ['type' => 'simple', 'visibility' => 'Not Visible Individually'];
        }

        // Parent detection: parentage_level=parent AND childSkus confirmed
        if ($parentageLevel === 'parent' && $hasChildSkus && !$hasFulfillmentAvailability) {
            return ['type' => 'variation', 'visibility' => 'catelog and search'];
        }

        // Parent fallback: child_relationship_type AND childSkus confirmed
        if (!empty($cpsr['child_relationship_type'] ?? null) && $hasChildSkus && !$hasFulfillmentAvailability) {
            return ['type' => 'variation', 'visibility' => 'catelog and search'];
        }

        // No confirmed variation signals → simple
        return ['type' => 'simple', 'visibility' => 'catelog and search'];
    }

    /**
     * to start sync status automatically for all users
     */
    public function processAutoSync(): void
    {
        try {
            $limit = 100;
            $skip = 0;
            $continue = true;

            $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollectionForTable('user_details');
            $this->di->getAppCode()->setAppTag('amazon_sales_channel');
            $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
            $appCodeArray = $appCode->toArray();
            $this->di->getAppCode()->set($appCodeArray);
            $planChecker = $this->di->getObjectManager()->get('\App\Plan\Models\Plan');
            do {
                $options = [
                    'limit' => $limit,
                    'skip' => $skip,
                    'sort' => ['_id' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => [
                        'user_id' => 1,
                        'shops' => 1
                    ]
                ];
                // filter users with amazon shop connected and shopify store active and installed users
                $userDetails = $collection->find(
                    [
                        'shops.1' => ['$exists' => true],
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => 'shopify',
                                'store_closed_at' => ['$exists' => false],
                                'apps.app_status' => 'active'
                            ]
                        ]
                    ],
                    $options
                )->toArray();

                foreach ($userDetails as $user) {
                    if (isset($user['user_id'])) {
                        $userId = (string)$user['user_id'];
                        // check if plan is active or not
                        $res = $planChecker->isPlanActive($userId);
                        if (isset($res['success']) && !$res['success']) continue;

                        $shops = $user['shops'] ?? [];
                        $sourceShopId = '';
                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && ($shop['marketplace'] == 'shopify')) {
                                $sourceShopId = (string)$shop['_id'];
                            } elseif (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                                //escape if warehouse is inactive or source shop id is not set
                                if (!empty($shop['warehouses']) && $shop['warehouses'][0]['status'] == 'inactive' || empty($sourceShopId)) {
                                    continue;
                                }

                                $data = [
                                    'user_id' => $userId,
                                    'source_marketplace' => [
                                        'marketplace' => 'shopify',
                                        'source_shop_id' => $sourceShopId
                                    ],
                                    'target_marketplace' => [
                                        'marketplace' => $shop['marketplace'],
                                        'target_shop_id' => (string)$shop['_id']
                                    ],
                                    'initiateImage' => true,
                                ];
                                $this->startSyncStatus($data);
                            }
                        }
                    }
                }

                if (count($userDetails) == $limit) {
                    $skip += $limit;
                } else {
                    $continue = false;
                }
            } while ($continue);
        } catch (Exception $e) {
            $this->addExceptionLog('Exception during cron run: ' . json_encode($e->getMessage()));
        }
    }

    public function addLog($data): void
    {
        $logFile =  'amazon/syncStatus/' . $this->userId . '/'  .date('Y-m-d') . '.log';
        $this->di->getLog()->logContent(
            print_r($this->userId . ' => ' .$data, true),
            'info',
            $logFile
        );
    }

    public function addExceptionLog($data): void
    {
        // $logFile =  'exceptionLog/syncStatus/' . $this->userId . '/' . date('Y-m-d') . '.log';
        $logFile =  'amazon/syncStatus/' . $this->userId . '/'  .date('Y-m-d') . '.log';
        $this->di->getLog()->logContent(
            print_r($this->userId . ' => ' .$data, true),
            'info',
            $logFile
        );
    }

    // public function addFailLog($data): void
    // {
    //     $logFile =  'exceptionLog/syncStatus/error' . $this->userId . '/' . date('Y-m-d') . '.log';
    //     $this->di->getLog()->logContent(
    //         print_r($data, true),
    //         'info',
    //         $logFile
    //     );
    // }

    public function syncStatusBatchCrone()
    {
        try {
            $objectManager = $this->di->getObjectManager();
            $mongo = $objectManager->create('\App\Core\Models\BaseMongo');
            $croneTable = $mongo->getCollectionForTable('amazon_status_sync');
            $processCode = $objectManager->get(CronHelper::class)->getProductReportQueueName();

            //set appCode appTag in di
            $this->di->getAppCode()->setAppTag('amazon_sales_channel');
            $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
            $appCodeArray = $appCode->toArray();
            $this->di->getAppCode()->set($appCodeArray);

            $appTag = $this->di->getAppCode()->getAppTag();
            //count total user
            $totalHours = 24;

            $userCount = $croneTable->count(
                ['appTag' => $appTag, 'process_code' => $processCode, 'last_executed_time' => ['$lt' => date('c', strtotime('-' . $totalHours . ' hours'))]]
            );
            if (!$userCount) return true;

            $limit = ceil($userCount / $totalHours);
            $users = $croneTable->find(
                ['appTag' => $appTag, 'process_code' => $processCode, 'last_executed_time' => ['$lt' => date('c', strtotime('-' . $totalHours . ' hours'))]],
                ['limit' => (int)$limit, 'typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();
            $uninstallUsers = [];
            $initiatedUsers = [];
            if (!empty($users)) {
                foreach ($users as $user) {
                    if (isset($user['user_id'], $user['source_shop_id'], $user['shop_id'])) {
                        //get users from user_details
                        $getUser = User::findFirst([['_id' => $user['user_id']]]);
                        if ($getUser) {
                            $getUser->id = (string) $getUser->_id;
                            $this->di->setUser($getUser);
                            $userId = (string) $getUser->_id;
                            $data = [
                                'user_id' => $userId,
                                'source_marketplace' => [
                                    'marketplace' => $user['source_marketplace'],
                                    'source_shop_id' => (string)$user['source_shop_id']
                                ],
                                'target_marketplace' => [
                                    'marketplace' => 'amazon',
                                    'target_shop_id' => (string)$user['shop_id']
                                ],
                            ];
                            $this->startSyncStatus($data);
                            array_push($initiatedUsers, new ObjectId($user['_id']));
                        } else {
                            array_push($uninstallUsers, new ObjectId($user['_id']));
                            continue;
                        }
                    }
                }
            }

            if (!empty($uninstallUsers)) {
                $croneTable->deleteMany(['_id' => ['$in' => $uninstallUsers]]);
            }

            if (!empty($initiatedUsers)) {
                $croneTable->updateMany(['_id' => ['$in' => $initiatedUsers]], ['$set' => ['last_executed_time' => date('c')]]);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in sync status crone = ' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
        return 1;
    }

    /**
     * to check if reponse has throttle
     */
    public function isResponseThrottled($response)
    {
        $isThrottle = false;
        if (isset($response['throttle']) && $response['throttle']) {
            $isThrottle = true;
        } elseif ((isset($response['code']) && ($response['code'] == 429))) {
            $isThrottle = true;
        }
        return $isThrottle;
    }
}
