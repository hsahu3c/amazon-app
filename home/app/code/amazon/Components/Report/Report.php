<?php
namespace App\Amazon\Components\Report;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class Report extends Base
{
    public const GET_MERCHANT_LISTINGS_ALL_DATA_REPORT = 'GET_MERCHANT_LISTINGS_ALL_DATA';

    public const GET_V2_SELLER_PERFORMANCE_REPORT = 'GET_V2_SELLER_PERFORMANCE_REPORT';

    public const GET_FLAT_FILE_VAT_INVOICE_DATA_REPORT = 'GET_FLAT_FILE_VAT_INVOICE_DATA_REPORT';

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

    public const WAREHOUSE_INACTIVE_ERROR = 'Sync status cannot be processed as warehouse is not active!';

    public const SUCCESS_SYNC_STATUS = 'Product(s) Sync Status update successful.';

    public const PROCESS_AUTOMATIC = 'automatic';

    public const PROCESS_MANUAL = 'manual';

    public const DEFAULT_PAGINATION_IMAGE = 60;


    public function requestReport(
        $remoteShopId,
        $shopId,
        $reportType = self::GET_MERCHANT_LISTINGS_ALL_DATA_REPORT,
        $startTime = null,
        $endTime = null
    )
    {
        $params = ['shop_id' => $remoteShopId, 'type' => $reportType, 'home_shop_id' => $shopId];
        if (!empty($startTime)) {
            $params['dataStartTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $params['dataEndTime'] = $endTime;
        }
        return $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('report-request', $params, 'GET');
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

    public function getReportById($remoteShopId, $reportId)
    {
        $params = [
            'shop_id' => $remoteShopId,
            'requests' => [['ReportRequestId' => $reportId]]
        ];
        return $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('report-fetch', $params, 'GET');
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
                                       $this->di->getObjectManager()->create('\App\Amazon\Components\Report\SyncStatus')->init($data)->executePerformanceReport();
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