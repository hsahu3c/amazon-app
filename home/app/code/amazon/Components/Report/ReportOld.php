<?php

namespace App\Amazon\Components\Report;

use App\Amazon\Components\Product\Lookup;
use Exception;
use App\Amazon\Components\Cron\Route\Requestcontrol;
use MongoDB\BSON\ObjectId;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Models\SourceModel;
use App\Core\Models\User;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class ReportOld extends Base
{
    private ?string $_user_id = null;

    private $_user_details;

    private $_baseMongo;

    public const GET_MERCHANT_LISTINGS_ALL_DATA_REPORT = 'GET_MERCHANT_LISTINGS_ALL_DATA';

    public const GET_V2_SELLER_PERFORMANCE_REPORT = 'GET_V2_SELLER_PERFORMANCE_REPORT';

    public $mappedReportHeading = [
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

    public const REPORT_REQUEST_OPERATION = 'request_report';

    public const REPORT_FETCH_OPERATION = 'fetch_report';

    public const IMPORT_REPORT_DATA_OPERATION = 'import_report_product';

    public const MATCH_STATUS_OPERATION = 'match_status';

    public const IMPORT_PRODUCT_IMAGE_OPERATION = 'import_image_product';

    public const MATCH_WITH_TITLE = 'title';

    public const MATCH_WITH_BARCODE = 'barcode';

    public const MATCH_WITH_SKU = 'sku';

    public const MATCH_WITH_SKU_AND_BARCODE  = 'sku,barcode';

    public const FULFILLED_BY_MERCHANT = 'FBM';

    public const FULFILLED_BY_AMAZON = 'FBA';

    public const MATCH_WITH_SKU_PRIORITY = 'sku_barcode';

    public const MATCH_WITH_BARCODE_PRIORITY = 'barcode_sku';

    public const CLOSE_MATCH_OPERATION = "close_match";

    public const ERROR_SYNC_STATUS = 'Sync status request could not be submitted on Amazon. Kindly contact support.';

    public const SUCCESS_SYNC_STATUS = 'Product(s) Sync Status update successful.';

    public const DEFAULT_PAGINATION_IMAGE = 60;
    private $source_marketplace = '';
    private $target_marketplace = 'amazon';

    private $source_shop_id;
    private $initiated = '';
    private $accounts = [];

    private ?string $match_with = null;

    private $lookup;
    private bool $initiateMatch = false;

    public function init($request = [])
    {
        if (isset($request['source_marketplace'])) {
            $this->source_marketplace = $request['source_marketplace']['marketplace'];
            $this->source_shop_id = $request['source_marketplace']['source_shop_id'];
        } elseif (isset($request['data']['source_shop_id'], $request['data']['source_marketplace'])) {
            $this->source_marketplace = $request['data']['source_marketplace'];
            $this->source_shop_id = (string)$request['data']['source_shop_id'];
        }

        if (isset($request['target_marketplace'])) {
            $this->target_marketplace = $request['target_marketplace']['marketplace'];
            $this->accounts = $request['target_marketplace']['target_shop_id'];
        } elseif (isset($request['data']['home_shop_id'], $request['data']['target_marketplace'])) {
            $this->target_marketplace = $request['data']['target_marketplace'];
            $this->accounts = (string)$request['data']['home_shop_id'];
        }

        if (isset($request['initiated'])) {
            $this->initiated = $request['initiated'];
        } else {
            $this->initiated = 'automatic';
        }

        if (isset($request['importProduct']) && $request['importProduct']) {
            $this->initiateMatch = false;
        }

        if (isset($this->source_shop_id, $this->accounts, $this->source_marketplace, $this->target_marketplace)) {
            $data = [
                'Ced-Source-Id' => $this->source_shop_id,
                'Ced-Source-Name' => $this->source_marketplace,
                'Ced-Target-Id' => $this->accounts,
                'Ced-Target-Name' => $this->target_marketplace
            ];
            $this->di->getObjectManager()->get('\App\Core\Components\Requester')->setHeaders($data);
        }

        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }

        if (isset($request['lookup'])) {
            $this->lookup = $request['lookup'];
        } else {
            $this->lookup = false;
        }

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    /**
     * initiate sync status
     *
     * @return array
     */
    public function execute($initiateImage = false)
    {
        try {
            $shop = $this->_user_details->getShop($this->accounts, $this->_user_id);
            if (!empty($shop)) {
                if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {
                    $queueName = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();
                    $appTag = $this->di->getAppCode()->getAppTag();
                    $queueTaskData = [
                        'user_id' => $this->_user_id,
                        'message' => 'Synchronization with Amazon Started',
                        'process_code' => $queueName,
                        'marketplace' =>  $this->target_marketplace,
                        'app_tag' => $appTag,
                        'process_initiation' => $this->initiated
                    ];
                    if (!$this->initiateMatch) {
                        $queueTaskData['additional_data'] = [
                            'initiateImage' => $initiateImage,
                            'lookup' => $this->lookup
                        ];
                    }

                    $queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
                    $queuedTaskId = $queuedTask->setQueuedTask($this->accounts, $queueTaskData);
                    if ($queuedTaskId) {
                        $data = [
                            'queued_task_id' => $queuedTaskId,
                            'remote_shop_id' => $shop['remote_shop_id']
                        ];
                        if ($this->initiateMatch) {
                            $handlerData = $this->prepareSqsData(self::MATCH_STATUS_OPERATION, $data);
                            $helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
                            $res = $helper->initiateSyncAfterImport($handlerData, true);
                            if (!$res) {
                                return ['success' => false, 'message' => 'The synchronization process could not be executed as there are no products available for synchronization.'];
                            }
                        } else {
                            $handlerData = $this->prepareSqsData(self::REPORT_REQUEST_OPERATION, $data);
                            $this->di->getMessageManager()->pushMessage($handlerData);
                            if ($this->lookup) {
                                /** execute seller-performance report in case of on-bording */
                                $this->executeReport();
                            }
                        }

                        return ['success' => true, 'message' => 'Please wait while the Sync Status is being initiated. The App will update the Product status once these Products match with your Amazon Seller Central products.'];
                    }
                    return ['success' => false, 'message' => 'Sync Status process from Amazon under progress. For more updates, please check your Notification window.'];
                }
                return ['success' => false, 'message' => 'Sync status error : Account is inactive.'];
            }
            return ['success' => false, 'message' => 'Shops not found'];
        } catch (Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /** get sqs-dat format for sync-status */
    public function prepareSqsData($action, $data = [])
    {
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $queueName = $data['queue_name'] ?? $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();
        return [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => $data['method'] ?? 'processProductReport',
            'appCode' => $appCode,
            'appTag' => $appTag,
            'queue_name' => $queueName,
            'user_id' => $data['user_id'] ?? $this->_user_id,
            'lookup' => $data['lookup'] ?? $this->lookup,
            'initiateImage' => $data['initiateImage'] ?? null,
            'data' => [
                'queued_task_id' => $data['queued_task_id'] ?? null,
                'home_shop_id' => $data['target_shop_id'] ?? $this->accounts,
                'remote_shop_id' => $data['remote_shop_id'] ?? null,
                'operation' => $action,
                'source_marketplace' => $data['source_marketplace'] ?? $this->source_marketplace,
                'target_marketplace' => $data['target_marketplace'] ?? $this->target_marketplace,
                'source_shop_id' => $data['source_shop_id'] ?? $this->source_shop_id
            ]
        ];
    }

    /**
     * validate progress status before processing by queued_task data
     *
     * @param array $sqsData
     * @return bool
     */
    public function validateProcess($sqsData)
    {
        try {
            $queueTable = $this->_baseMongo->getCollection('queued_tasks');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $query = ['_id' => new ObjectId($sqsData['data']['queued_task_id'])];
            $searchResponse = $queueTable->find($query, $options)->toArray();
            $skip = $sqsData['data']['page'] ?? 0;
            if (count($searchResponse) == 0) {
                return false;
            }
            if (isset($searchResponse[0]['additional_data']) && !is_array($searchResponse[0]['additional_data'])) {
                if ($searchResponse[0]['additional_data'] > $skip) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            print_r($e->getMessage());
            $this->di->getLog()->logContent('Exception validateProcess ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString()), 'info', 'exception.log');
            return false;
        }
    }

    /** redirect sync-status process with operation_type */
    public function process($sqsData)
    {
        $operation_type = $sqsData['data']['operation'] ?? self::REPORT_REQUEST_OPERATION;
        switch ($operation_type) {
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
                }

                break;
        }

        return true;
    }

    /** update queued_task progress and add notification if completed */
    public function updateProcess($queuedTaskId, $progress, $message = null, $severity = 'critical', $additionalData = null, $syncStatus = true): void
    {
        if ($progress >= 100 && $message) {
            if ($syncStatus) {
                $processCode = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();
            } else {
                $processCode = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->imageImportQueueName();
            }

            $notificationData = [
                'user_id' => $this->_user_id,
                'marketplace' => $this->target_marketplace,
                'appTag' => $this->di->getAppCode()->getAppTag(),
                'message' => $message,
                'severity' => $severity,
                'process_initiation' => $this->initiated,
                'process_code' => $processCode
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($this->accounts, $notificationData);
        }

        if (!$message && $progress >= 100) {
            $message = self::ERROR_SYNC_STATUS;
        }

        $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, $progress, $message, true, $additionalData);
    }

    /** search sync-status queued task with process code */
    public function getSyncStatusQueuedTask($userId, $targetShopId = null, $processCode = null, $queuedTaskId = null)
    {
        if (!$processCode) {
            $processCode = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();
        }

        $queueTable = $this->_baseMongo->getCollection('queued_tasks');
        if ($queuedTaskId) {
            // get queued task by id
            if (is_string($queuedTaskId)) {
                $queuedTaskId = new ObjectId($queuedTaskId);
            }

            return $queueTable->findOne(['_id' => $queuedTaskId], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        }
        if ($targetShopId) {
            //  get queued task by process code for target_shop
            $query = ['user_id' => $userId, 'shop_id' => $targetShopId, "process_code" => $processCode];
            return $queueTable->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        }
        //  get all queued task of sourceShop by process code
        $appTag = $this->di->getAppCode()->getAppTag();
        $query = ['user_id' => $userId, 'appTag' => $appTag, "process_code" => $processCode];
        return $queueTable->find($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
    }

    /**
     * create report on amazon
     *
     * @param array $sqsData
     */
    public function requestProductReport($sqsData, $reportType = self::GET_MERCHANT_LISTINGS_ALL_DATA_REPORT): void
    {
        $params = ['shop_id' => $sqsData['data']['remote_shop_id'], 'type' => $reportType, 'home_shop_id' => $this->accounts];
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('report-request', $params, 'GET');
        if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
            foreach ($response['response'] as $result) {
                if (!empty($sqsData['data']['queued_task_id'])) {
                    //  get queued-task with queued-task-id to store report_id in additional_data
                    $queuedTask = $this->getSyncStatusQueuedTask($this->_user_id, null, null, $sqsData['data']['queued_task_id']);
                    if (!empty($result['ReportRequestId']) && !empty($sqsData['data']['queued_task_id'])) {
                        if ($queuedTask) {
                            $additionalData = $queuedTask['additional_data'] ?? [];
                            $additionalData['report_id'] = $result['ReportRequestId'];
                            $this->updateProcess($sqsData['data']['queued_task_id'], 20, 'Success! Sync Status Report request submitted on Amazon.', null, $additionalData);
                        }

                        break;
                    }
                }
            }
        } elseif (!empty($sqsData['data']['queued_task_id'])) {
            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::ERROR_SYNC_STATUS);
        }
    }

    public function fetchProductReport($sqsData): void
    {
        // queued task is still in progress and report is not proceesed yet by webhook
        if (isset($sqsData['data']['queued_task_id'], $sqsData['data']['report_id'], $sqsData['data']['remote_shop_id'])) {
            $params = [
                'shop_id' => $sqsData['data']['remote_shop_id'] ?? '',
                'requests' => [['ReportRequestId' => $sqsData['data']['report_id']]]
            ];
            $response = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('report-fetch', $params, 'GET');
            if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
                foreach ($response['response'] as $result) {
                    if (!empty($result['report_document_url'])) {
                        //save report in file
                        $path = BP . DS . 'var' . DS . 'file' . DS . 'mfa' . DS . $sqsData['user_id'] . DS . $this->accounts . DS . 'report.tsv';
                        $compressionAlgo = $result['compression_algorithm'] ?? null;
                        $filePath = $this->downloadReportData($result['report_document_url'], $path, $compressionAlgo);
                        if (is_string($filePath)) {
                            $sqsData['data']['operation'] = self::IMPORT_REPORT_DATA_OPERATION;
                            $sqsData['data']['skip_character'] = 0;
                            $sqsData['data']['chunk'] = 500;
                            $sqsData['data']['file_path'] = $filePath;
                            $sqsData['data']['scanned'] = 0;
                            $this->di->getMessageManager()->pushMessage($sqsData);
                            $this->updateProcess($sqsData['data']['queued_task_id'], 20, 'Success! Sync Status Report fetched from Amazon. Product Import currently in Progress.');
                        } else {
                            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::ERROR_SYNC_STATUS);
                        }

                        break;
                    } elseif (isset($result['ReportProcessingStatus'])) {
                        if (in_array($result['ReportProcessingStatus'], ['IN_PROGRESS', 'IN_QUEUE'])) {
                            $this->di->getMessageManager()->pushMessage($sqsData);
                        } else {
                            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::SUCCESS_SYNC_STATUS, 'success');
                        }
                    } else {
                        $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::ERROR_SYNC_STATUS);
                    }

                    break;
                }
            } else {
                $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::ERROR_SYNC_STATUS);
            }
        }
    }

    /** ferch report from remote from amazon */
    public function fetchReportFromAmazon($remoteShopId, $reportType, $reportDocumentId, $path = null)
    {
        $params = [
            'shop_id' => $remoteShopId,
            'payload' => [
                'reportType' => $reportType,
                'reportDocumentId' => $reportDocumentId
            ]
        ];
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('get-report', $params, 'GET');
        if (isset($response['success'], $response['response']['report_document_url']) && $response['success'] && $response['response']['report_document_url'] != '') {
            $compressionAlgo = $response['response']['compression_algorithm'] ?? null;
            $response = $this->downloadReportData($response['response']['report_document_url'], $path, $compressionAlgo);
        }

        return $response;
    }

    /** download report content by url */
    public function downloadReportData($url, $filePath = null, $compressionAlgo = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            $data = null;
        }

        if ($compressionAlgo !== null && $compressionAlgo === "GZIP") {
            $data = gzdecode($data);
        }

        $is_gzip = 0 === mb_strpos($data, "\x1f" . "\x8b" . "\x08", 0, "US-ASCII");
        if ($is_gzip) {
            $data = gzdecode($data);
        }

        if (!$data) {
            return ['success' => false, 'message' => 'Error downloading report from url.'];
        }
        if ($filePath) {
            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
                }
            }

            $dirname = dirname((string) $filePath);
            if (!is_dir($dirname)) {
                $oldmask = umask(0);
                mkdir($dirname, 0777, true);
                umask($oldmask);
            }

            $fp = fopen($filePath, 'w+');
            $w = fwrite($fp, $data);
            fclose($fp);
            return $filePath;
        } else {
            return ['success' => true, 'response' => $data];
        }
    }

    /** import amazon-products in amazon_listing collection */
    public function saveAmazonListingProduct($sqsData): void
    {
        // how many line need to Read
        $row_chunk = $sqsData['data']['chunk'] ?? 500;
        $skip_character = $sqsData['data']['skip_character'] ?? 0;
        $myFile = $sqsData['data']['file_path'] ?? null;
        $scanned = $sqsData['data']['scanned'] ?? 0;
        $index = 0;
        $statusArray = [];
        clearstatcache();
        if ($myFile && file_exists($myFile) && ($handle = fopen($myFile, 'r')) !== false) {
            $bulkOpArray = [];
            $head = fgets($handle);
            $intHeader = explode("\n", $head);
            if (isset($intHeader[0])) {
                $header = $intHeader[0];
            } else {
                $header = '';
            }

            $heading = explode("\t", $header);
            // it will help you the Skip the charater which are already been used
            fseek($handle, $skip_character);
            // loop through the file line-by-line
            while (($splitContents = fgets($handle)) !== false && $index < $row_chunk) {
                $delimiter = "\n";
                $lines = explode($delimiter, $splitContents);
                if (isset($lines[0])) {
                    $line = $lines[0];
                } else {
                    $line = '';
                }

                // Your Operation starts
                if ($scanned > 0) {
                    $bits = explode("\t", $line);
                    if (count($bits) == count($heading)) {
                        $product = [];
                        foreach ($heading as $i => $key) {
                            if (!empty($bits[$i])) {
                                if (mb_detect_encoding($bits[$i], ['ASCII', 'UTF-8', 'SJIS', 'ISO-8859-1'], false)) {
                                    $b = mb_convert_encoding($bits[$i], 'UTF-8', mb_detect_encoding($bits[$i], ['ASCII', 'UTF-8', 'SJIS', 'ISO-8859-1'], false));
                                    $bits[$i] = iconv('UTF-8', 'UTF-8//IGNORE', $b);
                                } else {
                                    $bits[$i] = 'Undetected Characters';
                                }
                            }

                            if (mb_detect_encoding($key, ['ASCII', 'UTF-8', 'SJIS', 'ISO-8859-1'], false)) {
                                $k = mb_convert_encoding($key, 'UTF-8', mb_detect_encoding($key, ['ASCII', 'UTF-8', 'SJIS', 'ISO-8859-1'], false));
                                $kk = iconv('UTF-8', 'UTF-8//IGNORE', $k);
                                $key = $kk;
                            } else {
                                $key = 'undetected_key' . $i;
                            }

                            if (isset($this->mappedReportHeading[$key])) {
                                $product[$this->mappedReportHeading[$key]] = $bits[$i];
                            } else {
                                $product[$key] = $bits[$i];
                            }
                        }

                        if (!isset($product['asin1']) && isset($product['product-id'])) {
                            $product['asin1'] = $product['product-id'];
                        }

                        if (isset($product['image-url']) && $product['image-url'] == '') {
                            unset($product['image-url']);
                        }

                        $product['updated_at'] = date('c');
                        $product['source_marketplace'] = $this->source_marketplace;
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
                            if (isset($product['status'])) {
                                $statusArray[(string)$product['seller-sku']] =  $product['status'];
                            }
                        }
                    }
                }

                $index++;
                $skip_character = ftell($handle);
                $scanned++;
            }

            $helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
            if (!empty($statusArray)) {
                /** update status for manual linked products */
                $helper->updateManualLinkedProductStatus($statusArray, $sqsData['user_id'], $this->source_shop_id, $this->accounts);
                unset($statusArray);
            }

            if (!empty($bulkOpArray)) {
                $amazonCollection = $this->_baseMongo->getCollection(Helper::AMAZON_LISTING);
                $amazonCollection->BulkWrite($bulkOpArray, ['w' => 1]);
            }

            if ($scanned == $sqsData['data']['scanned'] || $index < $row_chunk - 3) {
                unlink($myFile);
                unset($sqsData['data']['skip_character'], $sqsData['data']['chunk'], $sqsData['data']['file_path'], $sqsData['data']['scanned'], $sqsData['data']['failed_asin']);
                /** initiate product matching */
                $helper->initiateSyncAfterImport($sqsData);
                /** initiate mark deleted product on amazon */
                $helper->updateDeleteStatus($sqsData);
                // $eventsManager = $this->di->getEventsManager();
                // $eventsManager->fire('application:inventoryOutOfStock', $this, [
                //     'user_id' => $sqsData['user_id'],
                //     'source_shop_id' => $this->source_shop_id,
                //     'target_shop_id' => $this->accounts
                // ]);
            } else {
                $sqsData['data']['scanned'] = $scanned;
                $sqsData['data']['skip_character'] = $skip_character;
                $this->di->getMessageManager()->pushMessage($sqsData);
            }
        } else {
            // report.txt not present
            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::SUCCESS_SYNC_STATUS, 'success');
        }
    }

    /** initiate image-import from amazon */
    public function saveAmazonListingImage($sqsData)
    {
        try {
            $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            (string)$userId = $this->_user_id ?? $sqsData['user_id'];
            $failedAsin = [];
            $amazonAsin = [];
            $bulkImageUpdate = [];
            $preparedData = $sqsData['data'];
            $counter = $preparedData['counter'] ?? 0;
            (int)$limit = $preparedData['limit'] ?? self::DEFAULT_PAGINATION_IMAGE;
            $lastPointer = $preparedData['lastPointer'] ?? '';
            $amazonCollection = $this->_baseMongo->getCollection(Helper::AMAZON_LISTING);
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $logFile = $this->getLogFile($userId, $sqsData, 'image_import');
            $amazonListing = [];
            $listingCount = 0;
            $targetShopId = $preparedData['home_shop_id'];
            if (!isset($preparedData['last_page']) || !$preparedData['last_page']) {
                $amazonListing = $amazonCollection->find(
                    ['_id' => ['$gt' => new ObjectId((string)$lastPointer)], 'user_id' => $userId, 'shop_id' => (string)$targetShopId, 'image-url' => null],
                    [
                        'projection' => ['asin1' => 1],
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => (int)$limit,
                    ]
                );
            }

            if (!empty($amazonListing)) {
                //asin from amazon_listing
                $amazonListing = $amazonListing->toArray();
                $amazonAsin = array_column($amazonListing, 'asin1');
                $listingCount = count($amazonAsin);
            }

            //failed asin
            if (isset($preparedData['failed_asin']) && is_array($preparedData['failed_asin'])) {
                $amazonAsin = [...$amazonAsin, ...$preparedData['failed_asin']];
            }

            $amazonAsin = array_unique($amazonAsin);
            if ($amazonAsin !== []) {
                $barcodeValidator = $this->di->getObjectManager()->create('App\Amazon\Components\Common\Barcode');
                $barcodeValidatedProducts = [];
                foreach($amazonAsin as $asin) {
                    $barcodeType = $barcodeValidator->setBarcode($asin);
                    if ($barcodeType) {
                        // // validate barcode agains preg-match to remove special cases
                        // if (preg_match('/(\x{200e}|\x{200f})/u', $asin)) {
                        //     $validBarcode = preg_replace('/(\x{200e}|\x{200f})/u', '', $asin);
                        // }
                        if ($barcodeType == 'EAN-8') $barcodeType = 'EAN';
                        $barcodeValidatedProducts[$barcodeType][] = $asin;
                    }
                }
                $isApiExhaust = false;
                $failedAsinsDueToThrottle = [];
                foreach($barcodeValidatedProducts as $type => $asinArray) {
                    $hundredAmazonAsin = array_chunk($asinArray, 40);
                    foreach ($hundredAmazonAsin as $twentyAsin) {
                        if (!$isApiExhaust) {
                            $params = [
                                'shop_id' => (string)$preparedData['remote_shop_id'],
                                'home_shop_id' => (string)$targetShopId,
                                'id_type' => $type,
                                'included_data' => 'images,relationships',
                                'id' => $twentyAsin
                            ];
                            $response = $commonHelper->sendRequestToAmazon('product', $params, 'GET');
                            if (isset($response['success']) && $response['success'] && isset($response['response']) && is_array($response['response'])) {
                                foreach ($response['response'] as $asin => $data) {
                                    $setProduct = [];
                                    if (is_array($data)) {
                                        if (isset($data['images']) && is_array($data['images'])) {
                                            if (isset($data['images'][0]['images']) && !empty($data['images'][0]['images'])) {
                                                $imageArray = $data['images'][0]['images'];
                                                foreach ($imageArray as $image) {
                                                    if (isset($image['link']) && $image['link'] !== "" && filter_var($image['link'], FILTER_VALIDATE_URL)) {
                                                        $image_updated = false;
                                                        if ($image['height'] <= 150 && $image['width'] <= 150) {
                                                            $setProduct['image-url'] = $image['link'];
                                                            $image_updated = true;
                                                            break;
                                                        }
                                                    }
                                                }
        
                                                if (isset($image_updated) && !$image_updated) {
                                                    foreach ($imageArray as $image) {
                                                        if (isset($image['link']) && $image['link'] !== "" && filter_var($image['link'], FILTER_VALIDATE_URL)) {
                                                            $setProduct['image-url'] = $image['link'];
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
        
                                        // get listing type :- parent or variants
                                        if (isset($data['relationships']) && !empty($data['relationships'])) {
                                            foreach ($data['relationships'] as $relationship) {
                                                if (isset($relationship['relationships'])) {
                                                    foreach ($relationship['relationships'] as $relationshipStatus) {
                                                        if (isset($relationshipStatus['parentAsins']) && isset($relationshipStatus['childAsins'])) {
                                                            //variation product
                                                            $setProduct['type'] = 'simple';
                                                            $setProduct['visibility'] = 'Not Visible Individually';
                                                            break;
                                                        } elseif (isset($relationshipStatus['childAsins'])) {
                                                            // parent product
                                                            $setProduct['type'] = 'variation';
                                                            $setProduct['visibility'] = 'catelog and search';
                                                            break;
                                                        } elseif (isset($relationshipStatus['parentAsins'])) {
                                                            //variation product
                                                            $setProduct['type'] = 'simple';
                                                            $setProduct['visibility'] = 'Not Visible Individually';
                                                            break;
                                                        }
                                                    }
                                                }
        
                                                if (isset($setProduct['type'])) {
                                                    break;
                                                }
                                            }
                                        }
        
                                        if (!isset($setProduct['image-url'])) {
                                            $setProduct['image-url'] = null;
                                        }
                                    }
        
                                    if (!empty($setProduct)) {
                                        $bulkImageUpdate[] =
                                            [
                                                'updateMany' => [
                                                    ["user_id" => $userId, 'shop_id' =>  (string)$targetShopId, 'asin1' => $asin],
                                                    ['$set' => $setProduct]
                                                ]
                                            ];
                                    }
                                }
                            } else {
                                $this->di->getLog()->logContent('saveAmazonListingImage response = ' . print_r($response, true), 'info', $logFile);
                                $listingCount = 0;
                                break;
                            }
                        }

                        if (isset($response['throttled']) && $response['throttled']) {
                            $isApiExhaust = true;
                            if (!isset($failedAsinsDueToThrottle[$type])) {
                                $failedProductsDueToThrottle[$type] = $twentyAsin;
                            } else {        
                                $failedAsinsDueToThrottle[$barcodeType] = $failedAsinsDueToThrottle[$type] + $twentyAsin;
                            }
                            // $skip = $index * 40;
                            // $failedAsin = array_slice($amazonAsin, $skip);
                            // break;
                        }
                    }
                }
                

                if (!empty($bulkImageUpdate)) {
                    $amazonCollection->BulkWrite($bulkImageUpdate, ['w' => 1]);
                }

                if ($listingCount >= $limit) {
                    $individuaWeight = $preparedData['individual_weight'];
                    $progress = round(100 / $individuaWeight, 6);
                    $preparedData['lastPointer'] = (string)end($amazonListing)['_id'];
                    $preparedData['failed_asin'] = $response['failed_asin'] ?? [];
                    $sqsData['data'] = $preparedData;
                    $this->updateProcess($sqsData['data']['queued_task_id'], $progress);
                    unset($sqsData['delay']);
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return true;
                }
                if ($counter <= 2 && $failedAsin !== []) {
                    $preparedData['last_page'] = true;
                    $preparedData['failed_asin'] = $response['failed_asin'] ?? [];
                    $counter += 1;
                    $preparedData['counter'] = $counter;
                    $sqsData['data'] = $preparedData;
                    $sqsData['delay'] = 30;
                    $this->di->getMessageManager()->pushMessage($sqsData);
                    return true;
                }
                $this->updateProcess($sqsData['data']['queued_task_id'], 100, 'Image(s) of Amazon listing synced successfully.', 'success', null, false);
                return true;
            }
            $this->updateProcess($sqsData['data']['queued_task_id'], 100, 'Image(s) of Amazon listing synced successfully.', 'success', null, false);

            return true;
        } catch (Exception $e) {
            print_r($e->getMessage());
            $this->di->getLog()->logContent('exception in saveAmazonListingImage = ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString()), 'info', 'exception.log');
            return false;
        }
    }

    public function matchStatus($sqsData)
    {
        if ($sqsData['user_id'] !== false && isset($sqsData['data']['home_shop_id']) && $sqsData['data']['home_shop_id']) {
            $userId = $sqsData['user_id'];
            $logFile = $this->getLogFile($userId, $sqsData, 'sync_status');
            $sourceMarketplace = $sqsData['data']['source_marketplace'] ?? $this->source_marketplace;
            $targetMarketplace = $sqsData['data']['target_marketplace'] ?? $this->target_marketplace;
            if (!isset($sqsData['data']['limit'])) {
                $limit = 500;
            } else {
                $limit = $sqsData['data']['limit'];
            }

            if (!isset($sqsData['data']['page'])) {
                $skip = 0;
            } else {
                $skip = $sqsData['data']['page'];
            }

            if (!$this->validateProcess($sqsData)) return true;

            $source_shop_id = $sqsData['data']['source_shop_id'];
            $shopId = $sqsData['data']['home_shop_id'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonCollection = $mongo->getCollection(Helper::AMAZON_LISTING);
            $query = ['user_id' => (string)$userId, 'shop_id' => (string)$shopId, 'manual_mapped' => null, 'automate_sync' => null];
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
                    'fulfillment-channel' => 1
                ],
                "sort" => ['_id' => 1],
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

            $amazonListing = $amazonCollection->find($query, $options);
            $listing = $amazonListing->toArray();
            $listingCount = count($listing);
            $inventorySync = [];
            if ($listingCount > 0) {
                $dataRequiredToMatch = [];
                $dataRequiredToMatch['shop_id'] = $shopId;
                $dataRequiredToMatch['source_shop_id'] = $source_shop_id;
                $dataRequiredToMatch['sync_preference'] = $sqsData['data']['sync_preference'] ?? [];
                $dataRequiredToMatch['source_marketplace'] = $sourceMarketplace;
                $inventorySync = $this->match($listing, $dataRequiredToMatch);
                if (!empty($inventorySync)) {
                    // $this->di->getLog()->logContent('Inventory sync initiated for source product ids = ' . print_r(array_keys($inventorySync), true), 'info', $logFile);
                    $notification = $this->di->getObjectManager()->get('\App\Connector\Models\Notifications');
                    $message = 'Inventory sync initiated for ' . count($inventorySync) . ' product(s).';
                    $notificationData = [
                        "user_id" => $this->_user_id,
                        "marketplace" => $this->target_marketplace,
                        "appTag" => $this->di->getAppCode()->getAppTag(),
                        "message" => $message,
                        "severity" => "success",
                        "process_code" => "inventory_sync",
                        'process_initiation' => $this->initiated
                    ];
                    $notification->addNotification($shopId, $notificationData);
                    unset($notification->_id);
                    /** initiate inventory sync */
                    $dataToSent = [
                        'source' => [
                            'shopId' => $source_shop_id,
                            'marketplace' => $sourceMarketplace
                        ],
                        'target' => [
                            'shopId' => $shopId,
                            'marketplace' => $targetMarketplace
                        ],
                        'user_id' => $this->_user_id
                    ];
                    $reportHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
                    $reportHelper->inventorySyncAfterLinking(array_keys($inventorySync), $dataToSent);
                }
            }

            if ($listingCount >= $limit) {
                $next = (int)$limit;
                $sqsData['data']['page'] = (int)($skip + $next);
                $sqsData['data']['limit'] = $limit;
                $this->di->getMessageManager()->pushMessage($sqsData);
                $totalProducts = $sqsData['data']['individual_weight'];
                $progress = round((($skip + $limit) / $totalProducts) * 100, 2);
                $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['queued_task_id'], $progress, '', false, $skip + $limit);
                return true;
            }
            if (isset($sqsData['lookup']) && $sqsData['lookup'] == true) {
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
                    "user_id" => $this->_user_id,
                ];
                $res = $this->di->getObjectManager()->get(Lookup::class)
                    ->init($preparedData)->initiateLookup($preparedData);
            }
            $this->updateProcess($sqsData['data']['queued_task_id'], 100, self::SUCCESS_SYNC_STATUS, 'success');
            return true;
        }
    }

    public function match($products, $preparedReport)
    {
        try {
            $sourceMarketplace = $preparedReport['source_marketplace'];
            $objectManager = $this->di->getObjectManager();
            $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
            $productHelper = $objectManager->get(ProductHelper::class);
            $validator = $objectManager->get(SourceModel::class);
            $bulkupdateData = [];
            $bulkupdateasinData = [];
            $syncProductInv = [];
            $bulkUpdateAmazonData = [];
            $manualUnmapParams = [
                'data' => [
                    'user_id' => $this->_user_id,
                    'syncStatus' => true,
                ],
                'source' => [
                    'shopId' => $this->source_shop_id,
                    'marketplace' => $sourceMarketplace
                ],
                'target' => [
                    'shopId' => $this->accounts,
                    'marketplace' => 'amazon'
                ]
            ];
            $syncPreference = $preparedReport['sync_preference'];
            foreach ($products as $amzonProduct) {
                if (isset($amzonProduct['status']) && $amzonProduct['status'] == 'Deleted') {
                    /** do not link / unlink products identify deleted on amazon */
                    continue;
                }

                $isParent = false;
                if (isset($amzonProduct['type']) && $amzonProduct['type'] == 'variation') $isParent = true;

                $fulfillmentChannel = $amzonProduct['fulfillment-channel'] ?? 'DEFAULT';
                if ($fulfillmentChannel == 'DEFAULT') {
                    $channel = self::FULFILLED_BY_MERCHANT;
                } else {
                    $channel = self::FULFILLED_BY_AMAZON;
                }

                $matchWith = $syncPreference[$channel] ?? false;
                if (!$matchWith) continue;

                $productData = $this->getProductForMatching($amzonProduct, $matchWith, $isParent);
                if (count($productData) == 1 && isset($productData[0]) && $matchWith != self::MATCH_WITH_TITLE) {
                    $productData = $productData[0];
                    if (array_key_exists($productData['source_product_id'], $syncProductInv)) continue;

                    $status = $amzonProduct['status'];
                    if ($isParent) {
                        $status = 'parent_' . $status;
                    }

                    if (
                        isset($amzonProduct['source_product_id']) && $amzonProduct['source_product_id'] == $productData['source_product_id']
                        && isset($productData['target_listing_id']) && $productData['target_listing_id'] == (string)$amzonProduct['_id']
                    ) {
                        if ($productData['status'] != $amzonProduct['status']) {
                            //check and update status if needed in already mapped product
                            $asinData = [
                                'source_product_id' => $productData['source_product_id'],
                                'container_id' => $productData['container_id'],
                                'shop_id' => $this->accounts,
                                'source_shop_id' => $this->source_shop_id,
                                'target_marketplace' => $this->target_marketplace,
                                'status' => $status,
                                'fulfillment_channel_code' => $fulfillmentChannel,
                                'fulfillment_type' => $channel
                            ];
                            array_push($bulkupdateasinData, $asinData);
                        }
                    } else {
                        $manualUnmapParams['data']['source_product_id'] = $productData['source_product_id'];
                        $productHelper->manualUnmap($manualUnmapParams);
                        $asin = $amzonProduct['asin1'] ?? $amzonProduct['asin2'] ?? $amzonProduct['asin3'] ?? '';
                        //validate product for unique sku in marketplace
                        $asinData = [
                            'shop_id' => $this->accounts,
                            'source_product_id' => $productData['source_product_id'],
                            'container_id' => $productData['container_id'],
                            'target_marketplace' => 'amazon',
                            'source_shop_id' => $preparedReport['source_shop_id'],
                            'sku' => $amzonProduct['seller-sku'],
                            'shopify_sku' => $productData['sku'],
                            'status' => $status,
                            'asin' => $asin,
                            'fulfillment_type' => $channel,
                            'fulfillment_channel_code' => $fulfillmentChannel,
                            'target_listing_id' => (string)$amzonProduct['_id'],
                        ];
                        $valid = $validator->validateSaveProduct([$asinData], false, $manualUnmapParams);
                        if (isset($valid['success']) && !$valid['success']) continue;

                        if (!$isParent) {
                            $syncProductInv[$productData['source_product_id']] = $productData['source_product_id'];
                        }

                        if ($matchWith == self::MATCH_WITH_SKU_PRIORITY || $matchWith == self::MATCH_WITH_BARCODE_PRIORITY) {
                            if (isset($matchedProduct['barcode']) && $matchedProduct['barcode'] == $amzonProduct['product-id']) {
                                $matchWith = 'barcode';
                            } else {
                                $matchWith = 'sku';
                            }
                        }

                        // $updateData = [
                        //     "source_product_id" => $productData['source_product_id'], // required
                        //     'user_id' => $this->_user_id,
                        //     'source_shop_id' => $this->source_shop_id,
                        //     'container_id' => $productData['container_id'],
                        //     'childInfo' => [
                        //         'source_product_id' => $productData['source_product_id'], // required
                        //         'shop_id' => $this->accounts, // required
                        //         'status' => $status,
                        //         'target_marketplace' => 'amazon', // required
                        //         'asin' => $asin,
                        //         'sku' => $amzonProduct['seller-sku'],
                        //         'fulfillment_type' => $channel,
                        //         'target_listing_id' => (string)$amzonProduct['_id']
                        //     ]
                        // ];
                        // array_push($bulkupdateData, $updateData);
                        array_push($bulkupdateasinData, $asinData);

                        if (isset($amzonProduct['source_product_id']) && $amzonProduct['source_product_id'] != $productData['source_product_id']) {
                            $manualUnmapParams['data']['source_product_id'] = $amzonProduct['source_product_id'];
                            $productHelper->manualUnmap($manualUnmapParams);
                        }

                        $matchedData = [
                            'matched' => true,
                            'matchedwith' => $matchWith,
                            'matchedProduct' => [
                                'source_product_id' => $productData['source_product_id'],
                                'container_id' => $productData['container_id'],
                                'shop_id' => $this->source_shop_id,
                                'sku' => $productData['sku']  ?? '',
                                'matchedwith' => $matchWith,
                                'title' => $productData['title'] ?? '',
                                'barcode' => $productData['barcode'] ?? '',
                                'main_image' => $productData['main_image'] ?? ''
                            ],
                            'source_product_id' => $productData['source_product_id'],
                            'source_container_id' => $productData['container_id']
                        ];
                        $bulkUpdateAmazonData[] = [
                            'updateOne' => [
                                ['_id' => $amzonProduct['_id']],
                                ['$set' => $matchedData, '$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1]]
                            ],
                        ];
                    }
                } elseif (count($productData) || $matchWith == self::MATCH_WITH_TITLE) {
                    $ids = [];
                    if (empty($productData)) continue;

                    foreach ($productData as $matchedProduct) {
                        if ($matchWith == self::MATCH_WITH_SKU_PRIORITY || $matchWith == self::MATCH_WITH_BARCODE_PRIORITY) {
                            if (isset($matchedProduct['barcode']) && $matchedProduct['barcode'] == $amzonProduct['product-id']) {
                                $matchWith = 'barcode';
                            } else {
                                $matchWith = 'sku';
                            }
                        }

                        array_push($ids, [
                            'container_id' => $matchedProduct['container_id'],
                            'source_product_id' =>  $matchedProduct['source_product_id'],
                            'matchedwith' => $matchWith,
                            'shop_id' => $this->source_shop_id,
                            'main_image' => $matchedProduct['main_image'] ?? '',
                            'barcode' => $matchedProduct['barcode'] ?? '',
                            'title' => $matchedProduct['title'] ?? '',
                            'sku' => $matchedProduct['sku'] ?? '',
                        ]);
                    }

                    if (isset($amzonProduct['source_product_id'])) {
                        $manualUnmapParams['data']['source_product_id'] = $amzonProduct['source_product_id'];
                        $productHelper->manualUnmap($manualUnmapParams);
                    }

                    $bulkUpdateAmazonData[] = [
                        'updateOne' => [
                            ['_id' => $amzonProduct['_id']],
                            ['$set' => ['closeMatchedProduct' => $ids]]
                        ]
                    ];
                } else {
                    if (isset($amzonProduct['source_product_id'])) {
                        $manualUnmapParams['data']['source_product_id'] = $amzonProduct['source_product_id'];
                        $productHelper->manualUnmap($manualUnmapParams);
                    } elseif (isset($amzonProduct['closeMatchedProduct'])) {
                        $bulkUpdateAmazonData[] = [
                            'updateOne' => [
                                ['_id' => $amzonProduct['_id']],
                                ['$unset' => ['closeMatchedProduct' => 1]]
                            ]
                        ];
                    }
                }
            }

            // if (!empty($bulkupdateData)) {
            //     $helper->marketplaceSaveAndUpdate($bulkupdateData);
            // }
            if (!empty($bulkupdateasinData)) {
                $helper = $objectManager->get('\App\Connector\Models\Product\Edit');
                $helper->saveProduct($bulkupdateasinData, false, $manualUnmapParams);
            }

            if (!empty($bulkUpdateAmazonData)) {
                $amazonCollection = $this->_baseMongo->getCollection(Helper::AMAZON_LISTING);
                $amazonCollection->BulkWrite($bulkUpdateAmazonData, ['w' => 1]);
            }

            return $syncProductInv;
        } catch (Exception $exception) {
            $this->di->getLog()->logContent('sync-status exception= ' . print_r($exception->getMessage(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    public function getProductForMatching($product, $matchWith, $isParent = false)
    {
        $data = [
            'user_id' => $this->_user_id,
            'target_shop_id' => $this->accounts,
            'source_shop_id' => $this->source_shop_id
        ];
        $productData = [];
        switch ($matchWith) {
            case self::MATCH_WITH_BARCODE:
                if ($isParent) return [];

                $data['barcode'] = $product['product-id'];
                $productData = $this->getProductByBarcodeQuery($data);
                break;
            case self::MATCH_WITH_TITLE:
                $data['title'] = $product['item-name'];
                $productData = $this->getProductByTitleQuery($data);
                break;
            case self::MATCH_WITH_SKU_PRIORITY:
                $data['sku'] = $product['seller-sku'];
                $productData = $this->getMultipleProductBySku($data, $isParent);
                if (empty($productData) && !$isParent) {
                    $data['barcode'] = $product['product-id'];
                    $productData = $this->getProductByBarcodeQuery($data);
                }

                break;
            case self::MATCH_WITH_BARCODE_PRIORITY:
                if (!$isParent) {
                    $data['barcode'] = $product['product-id'];
                    $productData = $this->getProductByBarcodeQuery($data);
                    if (empty($productData)) {
                        $data['sku'] = $product['seller-sku'];
                        $productData = $this->getMultipleProductBySku($data, $isParent);
                    }
                }

                break;
            default:
                //matchWith=sku
                $data['sku'] = $product['seller-sku'];
                $productData = $this->getMultipleProductBySku($data, $isParent);
        }

        return $productData;
    }

    public function getMultipleProductBySku($data, $parent = false)
    {
        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        $option = [
            'projection' => ['source_product_id' => 1, 'container_id' => 1, 'sku' => 1, 'barcode' => 1, 'type' => 1, 'status' => 1, 'main_image' => 1, 'title' => 1, 'target_listing_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];
        //query for target marketplace data / edited product doc
        $query = ['user_id' => $data['user_id'], 'shop_id' => $data['target_shop_id'], 'sku' => $data['sku']];
        $targetProduct = $helper->getproductbyQuery($query, $option);
        $ids = [];
        if (count($targetProduct) >= 1) {
            foreach ($targetProduct as $index => $product) {
                if ($parent && $product['source_product_id'] != $product['container_id']) {
                    unset($targetProduct[$index]);
                } else if (!$parent && $product['source_product_id'] == $product['container_id']) {
                    unset($targetProduct[$index]);
                } else {
                    $ids[$product['source_product_id']] = $index;
                }
            }
        }

        $type = 'simple';
        if ($parent) {
            $type = 'variation';
        }

        $query = ['user_id' => $data['user_id'], 'shop_id' => $data['source_shop_id'], 'sku' => $data['sku'], 'type' => $type];
        $sourceProduct = $helper->getproductbyQuery($query, $option);
        if (!empty($sourceProduct) && is_iterable($sourceProduct)) {
            $sourceIds = array_keys($ids);
            if (count($targetProduct) == 0) {
                $manualSkip = true;
            } else {
                $manualSkip = false;
            }

            foreach ($sourceProduct as $product) {
                $source_product_id = $product['source_product_id'];
                if (in_array($source_product_id, $sourceIds)) {
                    $targetProduct[$ids[$source_product_id]] = $targetProduct[$ids[$source_product_id]] + $product;
                } else {
                    if ($manualSkip) {
                        //skip source data of manual linked products
                        $marketplace = $this->getMarketplace($product, $data['target_shop_id']);
                        if (isset($marketplace['matchedwith']) && $marketplace['matchedwith'] == 'manual') continue;
                    }

                    array_push($targetProduct, $product);
                }
            }
        }

        return $targetProduct;
    }

    public function getMarketplace($product, $targetShopId)
    {
        $productCollection = $this->_baseMongo->getCollection(Helper::PRODUCT_CONTAINER);
        $query = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $targetShopId,
            'container_id' => $product['container_id'] ?? "",
            'source_product_id' => $product['source_product_id'],
            'target_marketplace' => 'amazon'
        ];
        return $productCollection->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
    }

    /**
     * get product by barcode
     *
     * @param array $data
     * @return array
     */
    public function getProductByBarcodeQuery($data)
    {
        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        $option = [
            'projection' => ['source_product_id' => 1, 'container_id' => 1, 'sku' => 1, 'barcode' => 1, 'type' => 1, 'status' => 1, 'main_image' => 1, 'title' => 1, 'target_listing_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];
        //query for target marketplace data / edited product doc
        $query = ['user_id' => $data['user_id'], 'shop_id' => $data['target_shop_id'], 'barcode' => $data['barcode']];
        $targetProduct = $helper->getproductbyQuery($query, $option);
        if (count($targetProduct) >= 1) {
            foreach ($targetProduct as $index => $product) {
                if ($product['source_product_id'] == $product['container_id']) {
                    unset($targetProduct[$index]);
                }
            }
        }

        if (empty($targetProduct)) {
            //query for source marketplace data / edited product doc
            $query = ['user_id' => $data['user_id'], 'shop_id' => $data['source_shop_id'], 'type' => 'simple', 'barcode' => $data['barcode']];
            $targetProduct = $helper->getproductbyQuery($query, $option);
        }

        return $targetProduct;
    }

    public function getProductByTitleQuery($data)
    {

        $objectManager = $this->di->getObjectManager();
        $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
        $query = [
            'user_id' => $this->_user_id,
            'shop_id' => $data['source_shop_id'],
            'title' => trim((string) $data['title']),
            '$and' => [
                [
                    'type' => "simple"
                ],
                [
                    '$or' => [
                        ['marketplace.target_marketplace' => ['$ne' => 'amazon']],
                        ['marketplace.shop_id' => ['$ne' => $data['target_shop_id']]]
                    ]
                ],
                [
                    '$or' => [
                        ["marketplace.$.status" => ['$nin' => ['Active', 'Inactive', 'Incomplete']]],
                        ["marketplace.$.status" => ['$exists' => false]]
                    ]
                ]
            ]
        ];
        $option = [];
        $productDataTitle = $helper->getproductbyQuery($query, $option);
        return $productDataTitle;
    }

    public function getLogFile($user_id, $data, $action)
    {
        $date = date('d-m-Y');
        return "amazon/{$user_id}/{$data['data']['source_shop_id']}/{$data['data']['home_shop_id']}/{$action}/{$date}.log";
    }

    public function requestReport(): void
    {
        $cronTask = 'Sync Status';
        $limit = 100;
        $skip = 0;
        $continue = true;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $planChecker = $this->di->getObjectManager()->get('\App\Plan\Models\Plan');
        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user_details = $collection->find(['shops.marketplace' => 'amazon', 'uninstall_status' => null], $options)->toArray();
            foreach ($user_details as $user) {
                if (isset($user['user_id'])) {
                    $userId = (string)$user['user_id'];
                    // restrict sync cron for sonsOfGautham client
                    if ($userId == '613bca66a4d23a0dca3fbd9d') continue;

                    //check if sync allowed
                    $res = $planChecker->validateUserPlan(['user_id' => $userId]);
                    if (isset($res['success']) && !$res['success']) continue;

                    $shops = $user['shops'] ?? [];
                    if (is_iterable($shops)) {
                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                                $sources = $shop['sources'] ?? [];
                                if (is_iterable($sources)) {
                                    foreach ($sources as $sourceData) {
                                        if (!isset($sourceData['code'], $sourceData['shop_id'])) continue;

                                        $data = [
                                            'user_id' => $userId,
                                            'source_marketplace' => [
                                                'marketplace' => $sourceData['code'],
                                                'source_shop_id' => (string)$sourceData['shop_id']
                                            ],
                                            'target_marketplace' => [
                                                'marketplace' => $shop['marketplace'],
                                                'target_shop_id' => (string)$shop['_id']
                                            ],
                                        ];
                                        $this->init($data)->execute(true);
                                        //skip other sources because currently we have only one source
                                        continue;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (count($user_details) == $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }
        } while ($continue);
    }

    public function getSkuBulkTest($data)
    {
        try {
            //            $this->_user_id = '613bca66a4d23a0dca3fbd9d';
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollection('product_container');
            $filter = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $pipeline = [];
            array_push($pipeline, ['$match' => [
                'user_id' => $this->_user_id,
                'shop_id' => (string)$data['source_shop_id'],
                'type' => 'simple'
            ]]);
            array_push($pipeline, ['$match' => [
                '$or' => [
                    [
                        // match sku in target_marketplace
                        'marketplace' => [
                            '$elemMatch' => [
                                'shop_id' => (string)$data['target_shop_id'],
                                'sku' => ['$in' => $data['skus']]
                            ]
                        ]
                    ],
                    [
                        // if sku not present in target_marketplace, match sku present in source_marketplace
                        '$and' => [
                            [
                                'marketplace' => [
                                    '$elemMatch' => [
                                        'shop_id' => (string)$data['target_shop_id'],
                                        'sku' => ['$exists' => false]
                                    ]
                                ]
                            ],
                            [
                                'marketplace' => [
                                    '$elemMatch' => [
                                        'shop_id' => (string)$data['source_shop_id'],
                                        'sku' => ['$in' => $data['skus']]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        // if sku not present in target_marketplace, source_marketplace, match source-sku
                        '$and' => [
                            [
                                'marketplace' => [
                                    '$elemMatch' => [
                                        'shop_id' => (string)$data['target_shop_id'],
                                        'sku' => ['$exists' => false]
                                    ]
                                ]
                            ],
                            [
                                'marketplace' => [
                                    '$elemMatch' => [
                                        'shop_id' => (string)$data['source_shop_id'],
                                        'sku' =>  ['$exists' => false]
                                    ]
                                ]
                            ],
                            [
                                'sku' => ['$in' => $data['skus']]
                            ]
                        ]
                    ]
                ]
            ]]);
            array_push($pipeline, [
                '$addFields' => [
                    'matching_sku' => [
                        '$ifNull' => [
                            [
                                '$arrayElemAt' => [
                                    [
                                        '$setIntersection' => [
                                            [
                                                '$map' => [
                                                    'input' => '$marketplace',
                                                    'in' => [
                                                        '$cond' => [
                                                            ['$in' => ['$$this.sku', $data['skus']]],
                                                            '$$this.sku',
                                                            false
                                                        ]
                                                    ]
                                                ]
                                            ],
                                            $data['skus']
                                        ]
                                    ],
                                    0
                                ] // index of the first element in the resulting array
                            ],
                            '$sku'
                        ]
                    ]
                ]
            ]);
            array_push($pipeline, ['$group' => [
                '_id' => '$matching_sku',
                'totalCount' => ['$sum' => 1],
                'products' => ['$push' => [
                    'container_id' => '$container_id',
                    'source_product_id' => '$source_product_id',
                    'user_id' => '$user_id',
                    'main_image' => '$main_image',
                    'title' => '$title',
                    'barcode' => '$barcode',
                    'sku' => '$matching_sku',
                    'visibility' => '$visibility'
                ]]
            ]]);
            array_push($pipeline, ['$project' => ['_id' => 1, 'totalCount' => 1, 'products' => ['$slice' => ['$products', 10]]]]);
            //             array_push($pipeline, ['$group' => ['_id' => null, 'products' => ['$push' => ['k' => '$_id', 'v' => '$$ROOT']]]]);
            //             array_push($pipeline, ['$replaceRoot' => ['newRoot' => ['$arrayToObject' => '$products']]]);

            $productData = $collection->aggregate($pipeline, $filter)->toArray();
            $productData = $this->formatQueryData($productData);
            return $productData;
        } catch (Exception $e) {
            print_r($e->getMessage());
        }

        return [];
    }

    public function formatQueryData($productData)
    {
        $returnData = [];
        if (is_array($productData)) {
            foreach ($productData as $data) {
                if (isset($data['_id'], $data['products'])) {
                    $returnData[$data['_id']] = [
                        'products' => $data['products'],
                        'totalCount' => $data['totalCount'] ?? count($data['products'])
                    ];
                }
            }
        }

        return $returnData;
    }

    public function matchTest($products, $preparedReport)
    {
        try {
            $amazonCollection = $this->_baseMongo->getCollection(Helper::AMAZON_LISTING);
            $shop = $this->_user_details->getShop($preparedReport['shop_id'], $this->_user_id);
            if (isset($preparedReport['matchWith'])) {
                $matchWith = $preparedReport['matchWith'];
            } else {
                $appTag = $this->di->getAppCode()->getAppTag();
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setGroupCode('product');
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setUserId($this->_user_id);
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->sourceSet($this->source_marketplace);
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setTarget($this->target_marketplace);
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setAppTag($appTag);
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setSourceShopId($this->source_shop_id);
                $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->setTargetShopId($this->accounts);
                $configData = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config')->getConfig('sync_status_with');
                $val = json_decode(json_encode($configData), true);
                if (isset($val[0])) {
                    if (isset($val[0]['value']['sku']) && isset($val[0]['value']['barcode'])) {
                        $matchWith_sku = $val[0]['value']['sku'];
                        $matchWith_barcode = $val[0]['value']['barcode'];
                        if ($matchWith_sku == true && $matchWith_barcode == false) {
                            $this->match_with = self::MATCH_WITH_SKU;
                        } elseif ($matchWith_sku == false && $matchWith_barcode == true) {
                            $this->match_with = self::MATCH_WITH_BARCODE;
                        } elseif ($matchWith_sku == true && $matchWith_barcode == true) {
                            $this->match_with = self::MATCH_WITH_SKU_AND_BARCODE;
                        }
                    } else {
                        $this->match_with = self::MATCH_WITH_SKU;
                    }
                }

                $matchWith = $this->match_with;
            }

            $seller_id = $shop['warehouses'][0]['seller_id'];
            $bulkupdateData = [];
            $bulkupdateasinData = [];
            $syncProductInv = [];
            $bulkAmazonUpdate = [];
            $sellerSkus = array_column($products, 'seller-sku');
            $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
            $data = [
                "skus" => $sellerSkus,
                'user_id' => $this->_user_id,
                'target_shop_id' => $shop['_id'],
                'source_shop_id' => $preparedReport['source_shop_id']
            ];
            $productsData = $this->getSkuBulkTest($data);
            if (!$productsData) return [];

            foreach ($products as $product) {
                $amazonAttribute = $product['seller-sku'];
                $sourceProductIdsForMatch = 0;
                $productData = [];
                if (isset($productsData[$amazonAttribute], $productsData[$amazonAttribute]['totalCount'], $productsData[$amazonAttribute]['products'])) {
                    $sourceProductIdsForMatch = $productsData[$amazonAttribute]['totalCount'];
                    if ($sourceProductIdsForMatch && !empty($productsData[$amazonAttribute]['products'])) {
                        $productData['sku'] = $productsData[$amazonAttribute]['products'];
                    }
                }

                if ($sourceProductIdsForMatch == 1 && $matchWith != self::MATCH_WITH_TITLE) {
                    foreach ($productData as $match_With => $matchedProducts) {
                        foreach ($matchedProducts as $productData) {
                            if (isset($product['source_product_id']) && $product['source_product_id'] == $productData['source_product_id']) {
                                $asin = '';
                                if (isset($product['asin1']) && $product['asin1'] != '') {
                                    $asin = $product['asin1'];
                                } elseif (isset($product['asin2']) && $product['asin2'] != '') {
                                    $asin = $product['asin2'];
                                } elseif (isset($product['asin3']) && $product['asin3'] != '') {
                                    $asin = $product['asin3'];
                                }

                                if ($asin != '') {
                                    // update asin on product container
                                    $asinData = [
                                        'target_marketplace' => 'amazon',
                                        'shop_id' => (string)$shop['_id'],
                                        'container_id' => $productData['container_id'],
                                        'source_product_id' => $productData['source_product_id'],
                                        'status' => $product['status'],
                                        'source_shop_id' => $preparedReport['source_shop_id']
                                    ];
                                    array_push($bulkupdateasinData, $asinData);
                                }
                            } else {
                                if (array_key_exists($productData['source_product_id'], $syncProductInv)) continue;

                                $manualUnmapParams = [
                                    'data' => [
                                        'source_product_id' => $productData['source_product_id'],
                                        'user_id' => $this->_user_id,
                                        'syncStatus' => true,
                                    ],
                                    'source' => [
                                        'shopId' => $preparedReport['source_shop_id'],
                                        'marketplace' => $this->source_marketplace
                                    ],
                                    'target' => [
                                        'shopId' => $shop['_id'],
                                        'marketplace' => 'amazon'
                                    ]
                                ];

                                $productHelper->manualUnmap($manualUnmapParams);
                                if (isset($productData['type']) && $productData['type'] == 'variation') continue;

                                $asin = '';
                                if (isset($product['asin1']) && $product['asin1'] != '') {
                                    $asin = $product['asin1'];
                                } elseif (isset($product['asin2']) && $product['asin2'] != '') {
                                    $asin = $product['asin2'];
                                } elseif (isset($product['asin3']) && $product['asin3'] != '') {
                                    $asin = $product['asin3'];
                                }

                                $updateData = [
                                    "source_product_id" => $productData['source_product_id'], // required
                                    'user_id' => $productData['user_id'],
                                    'source_shop_id' => $preparedReport['source_shop_id'],
                                    'container_id' => $productData['container_id'],
                                    'childInfo' => [
                                        'source_product_id' => $productData['source_product_id'], // required
                                        'shop_id' => (string)$shop['_id'], // required
                                        'status' => $product['status'],
                                        'target_marketplace' => 'amazon', // required
                                        'asin' => $asin,
                                        'seller_id' => $seller_id,
                                        'sku' => $amazonAttribute,
                                        "target_listing_id" => (string)$product['_id']
                                    ]
                                ];
                                array_push($bulkupdateData, $updateData);
                                // print_r($bulkupdateData);die;
                                if ($asin != '') {
                                    // update asin on product container
                                    $asinData = [
                                        'target_marketplace' => 'amazon',
                                        'shop_id' => (string)$shop['_id'],
                                        'container_id' => $productData['container_id'],
                                        'source_product_id' => $productData['source_product_id'],
                                        'asin' => $asin,
                                        'sku' => $amazonAttribute,
                                        'status' => $product['status'],
                                        "target_listing_id" => (string)$product['_id'],
                                        'source_shop_id' => $preparedReport['source_shop_id'],
                                    ];
                                    array_push($bulkupdateasinData, $asinData);
                                }

                                $matchedData = [];
                                $matchedData['matched'] = true;
                                $matchedData['source_product_id'] = $productData['source_product_id'];
                                $matchedData['source_container_id'] = $productData['container_id'];
                                $matchedData['matchedwith'] = $match_With;

                                if (!isset($product['source_product_id']) || (isset($product['source_product_id']) && $product['source_product_id'] != $productData['source_product_id'])) {
                                    $syncProductInv[$productData['source_product_id']] = $productData;
                                    if (isset($product['source_product_id']) && $product['source_product_id'] != $productData['source_product_id']) {
                                        $manualUnmapParams = [
                                            'data' => [
                                                'source_product_id' => $product['source_product_id'],
                                                'user_id' => $this->_user_id,
                                                'syncStatus' => true,
                                            ],
                                            'source' => [
                                                'shopId' => $preparedReport['source_shop_id'],
                                                'marketplace' => $this->source_marketplace
                                            ],
                                            'target' => [
                                                'shopId' => $shop['_id'],
                                                'marketplace' => 'amazon'
                                            ]
                                        ];
                                        $productHelper->manualUnmap($manualUnmapParams);
                                    }
                                }

                                $productData['title'] ??= '';
                                $productData['barcode'] ??= '';
                                $productData['main_image'] ??= '';

                                $matchedData['matchedProduct'] = [
                                    'container_id' => $productData['container_id'],
                                    'source_product_id' => $productData['source_product_id'],
                                    'main_image' => $productData['main_image'],
                                    'title' => $productData['title'],
                                    'barcode' => $productData['barcode'],
                                    'sku' => $productData['sku'],
                                    'shop_id' => $preparedReport['source_shop_id'],
                                    'matchedwith' => $match_With
                                ];
                                $bulkAmazonUpdate[] =
                                    [
                                        'updateOne' => [
                                            ['_id' => $product['_id']],
                                            ['$set' => $matchedData]
                                        ]
                                    ];
                                // $amazonCollection->updateOne(['_id' => $product['_id']], ['$set' => $matchedData]);
                            }
                        }
                    }
                } elseif ($sourceProductIdsForMatch > 1  || ($matchWith == self::MATCH_WITH_TITLE)) {
                    $ids = [];
                    if (isset($product['source_product_id'])) {
                        $manualUnmapParams = [
                            'data' => [
                                'source_product_id' => $product['source_product_id'],
                                'user_id' => $this->_user_id,
                                'syncStatus' => true,
                            ],
                            'source' => [
                                'shopId' => $preparedReport['source_shop_id'],
                                'marketplace' => $this->source_marketplace
                            ],
                            'target' => [
                                'shopId' => (string)$shop['_id'],
                                'marketplace' => 'amazon'
                            ]
                        ];
                        $productHelper->manualUnmap($manualUnmapParams);
                    }

                    foreach ($productData as $match_With => $matchedProducts) {
                        foreach ($matchedProducts as $productData) {
                            $productData['title'] ??= '';
                            $productData['barcode'] ??= '';
                            $productData['main_image'] ??= '';
                            array_push($ids, [
                                'container_id' => $productData['container_id'],
                                'source_product_id' =>  $productData['source_product_id'],
                                'matchedwith' => $match_With,
                                'shop_id' => $preparedReport['source_shop_id'],
                                'main_image' => $productData['main_image'],
                                'barcode' => $productData['barcode'],
                                'title' => $productData['title'],
                                'sku' => $productData['sku'],
                            ]);
                        }

                        // $product['closeMatchedProduct'] = $ids;
                        $bulkAmazonUpdate[] =
                            [
                                'updateOne' => [
                                    ['_id' => $product['_id']],
                                    ['$set' => [
                                        'closeMatchedProduct' => $ids
                                    ]]
                                ]
                            ];
                        // $amazonCollection->updateOne(['_id' => $product['_id']], ['$set' => $product]);
                    }
                }
            }

            if (!empty($bulkAmazonUpdate)) {
                $res = $amazonCollection->BulkWrite($bulkAmazonUpdate, ['w' => 1]);
            }

            if (!empty($bulkupdateData)) {
                $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $helper->marketplaceSaveAndUpdate($bulkupdateData);
            }

            if (!empty($bulkupdateasinData)) {
                $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $helper->saveProduct($bulkupdateasinData);
            }

            return $syncProductInv;
        } catch (Exception $exception) {
            // print_r($exception->getMessage());
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    public function syncStatusBatchCrone()
    {
        $logFile =  'syncStatus/' . date('Y-m-d') . '.log';
        try {
            $objectManager = $this->di->getObjectManager();
            $mongo = $objectManager->create('\App\Core\Models\BaseMongo');
            $crone_table = $mongo->getCollectionForTable('amazon_status_sync');
            $process_code = $objectManager->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();

            //set appCode appTag in di
            $this->di->getAppCode()->setAppTag('amazon_sales_channel');
            $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
            $appCodeArray = $appCode->toArray();
            $this->di->getAppCode()->set($appCodeArray);

            $appTag = $this->di->getAppCode()->getAppTag();
            //count total user
            $totalHours = 24;

            $userCount = $crone_table->count(
                ['appTag' => $appTag, 'process_code' => $process_code, 'last_executed_time' => ['$lt' => date('c', strtotime('-' . $totalHours . ' hours'))]]
            );
            if (!$userCount) return true;

            $limit = ceil($userCount / $totalHours);
            $users = $crone_table->find(
                ['appTag' => $appTag, 'process_code' => $process_code, 'last_executed_time' => ['$lt' => date('c', strtotime('-' . $totalHours . ' hours'))]],
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
                            $this->init($data)->execute(true);
                            array_push($initiatedUsers, new ObjectId($user['_id']));
                        } else {
                            array_push($uninstallUsers, new ObjectId($user['_id']));
                            continue;
                        }
                    }
                }
            }

            if (!empty($uninstallUsers)) {
                $crone_table->deleteMany(['_id' => ['$in' => $uninstallUsers]]);
            }

            if (!empty($initiatedUsers)) {
                $crone_table->updateMany(['_id' => ['$in' => $initiatedUsers]], ['$set' => ['last_executed_time' => date('c')]]);
            }
        } catch (Exception $e) {
            print_r($e->getMessage());
            $this->di->getLog()->logContent('Exception in sync status crone = ' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }

        return 1;
    }

    /** initiate seller-performance report for each client */
    public function executeReport()
    {
        try {
            $shop = $this->_user_details->getShop($this->accounts, $this->_user_id);
            if (!empty($shop)) {
                if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {
                    $queueName = 'amazon_product_seller_performance_report';
                    $data = [
                        'queue_name' => $queueName,
                        'remote_shop_id' => $shop['remote_shop_id'],
                        'method' => 'processSellerPerformanceReport'
                    ];
                    $handlerData = $this->prepareSqsData(self::REPORT_REQUEST_OPERATION, $data);
                    $this->di->getMessageManager()->pushMessage($handlerData);
                    return ['success' => true, 'message' => 'Seller performance report initiated'];
                }
                return ['success' => false, 'message' => ' Inactive seller id'];
            }
            return ['success' => false, 'message' => 'Shops not found'];
        } catch (Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /** cron for selller-performance report */
    public function fetchReport(): void
    {
        $cronTask = 'Seller Performance';
        $limit = 100;
        $skip = 0;
        $continue = true;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $planChecker = $this->di->getObjectManager()->get('\App\Plan\Models\Plan');
        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user_details = $collection->find(['shops.marketplace' => 'amazon', 'uninstall_status' => null], $options)->toArray();
            foreach ($user_details as $user) {
                if (isset($user['user_id'])) {
                    $userId = (string)$user['user_id'];
                    //check if sync allowed
                    $res = $planChecker->validateUserPlan(['user_id' => $userId]);
                    if (isset($res['success']) && !$res['success']) continue;

                    $shops = $user['shops'] ?? [];
                    if (is_array($shops)) {
                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                                $sources = $shop['sources'] ?? [];
                                if (is_array($sources)) {
                                    foreach ($sources as $sourceData) {
                                        if (!isset($sourceData['code'], $sourceData['shop_id'])) continue;

                                        $data = [
                                            'user_id' => $userId,
                                            'source_marketplace' => [
                                                'marketplace' => $sourceData['code'],
                                                'source_shop_id' => (string)$sourceData['shop_id']
                                            ],
                                            'target_marketplace' => [
                                                'marketplace' => $shop['marketplace'],
                                                'target_shop_id' => (string)$shop['_id']
                                            ],
                                        ];
                                        $this->init($data)->executeReport($data);
                                        //skip other sources because currently we have only one source
                                        continue;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (count($user_details) == $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }

            // echo count($user_details) . " merchants processed.";
        } while ($continue);

        // echo "Total {$totalShopsCount} shops pushed in sqs for {$cronTask}.";
    }
}
