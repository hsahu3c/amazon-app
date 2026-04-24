<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class Product extends Base
{
    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    const PRODUCT_TYPE_SIMPLE = 'simple';

    const PRODUCT_TYPE_VARIANT = 'variant';

    const PRODUCT_TYPE_VARIATION = 'variation';

    const VISIBILITY_NOT_VISIBLE_INDIVIDUALLY = 'Not Visible Individually';

    const VISIBILITY_CATALOG_AND_SEARCH = 'Catalog and Search';

    public $sqlConfig;

    const productChunkSize = 2;

    public static $defaultPagination = 20;

    public function initialize(): void
    {
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(Product::IS_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` = '" . trim(addslashes($value[Product::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Product::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` != '" . trim(addslashes($value[Product::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Product::IS_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Product::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(Product::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` NOT LIKE '%" . trim(addslashes($value[Product::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(Product::START_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '" . trim(addslashes($value[Product::START_FROM])) . "%'";
                } elseif (array_key_exists(Product::END_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Product::END_FROM])) . "'";
                } elseif (array_key_exists(Product::RANGE, $value)) {
                    if (trim($value[Product::RANGE]['from']) && !trim($value[Product::RANGE]['to'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Product::RANGE]['from'] . "'";
                    } elseif (trim($value[Product::RANGE]['to']) && !trim($value[Product::RANGE]['from'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Product::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "`" . $key . "` between '" . $value[Product::RANGE]['from'] . "' AND '" . $value[Product::RANGE]['to'] . "'";
                    }
                }
            }
        }

        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = "MATCH(" . $fullTextSearchColumns . ") AGAINST ('" . trim(addslashes($filterParams['search'])) . "' IN NATURAL LANGUAGE MODE)";
        }

        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }

        return $conditionalQuery;
    }

    public function mergeArrays($arr1, $arr2)
    {
        $responseArray = [];
        foreach ($arr1 as $key => $value) {
            $responseArray[] = $value;
        }

        foreach ($arr2 as $value) {
            $responseArray[] = $value;
        }

        return $responseArray;
    }

    public function implodeArrays($array, $aliaz, $prefix)
    {
        $response = '';
        if ($prefix == 'variant') {
            $merchantId = $this->di->getUser()->id;
            $market_attributes = [];
            $marketplaceAttribute = ProductAttribute::find(["columns" => "code", "merchant_id='" . $merchantId . "' AND apply_on != 'base'"])
                ->toArray();
            foreach ($marketplaceAttribute as $val) {
                $market_attributes[] = $val['code'];
            }
        }

        foreach ($array as $value) {
            $end = array_search($value, $array) < (count($array) - 1) ? ',' : '';
            if ($prefix == 'variant') {
                if (in_array($value, $market_attributes)) {
                    $response .= " JSON_VALUE(" . $aliaz . "." . "additional_info, '$." . $value . "') as " . $value . $end;
                } else {
                    $response .= ' ' . $aliaz . '.' . $value . $end;
                }
            } else {
                $response .= ' ' . $aliaz . '.' . $value . $end;
            }
        }

        return $response;
    }

    public function getAllVariant($params)
    {
        $limit = $params['count'] ?? self::$defaultPagination;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM product_" . $this->di->getUser()->id . " WHERE ";
        $conditionalQuery = "";
        if (isset($params['filter']) || isset($params['search'])) {
            $fullTextSearchColumns = "`sku`";
            $conditionalQuery = self::search($params, $fullTextSearchColumns);
        }

        if ($conditionalQuery) {
            $query .= $conditionalQuery;
        } else {
            $query .= 1;
        }

        $query .= " LIMIT " . $limit . " OFFSET " . $offset;
        $responseData = [];
        $collection = $this->sqlConfig->sqlRecords($query, "all");
        $responseData['success'] = true;
        $responseData['data']['count'] = 0;
        $responseData['data']['rows'] = "";
        if (is_array($collection) && count($collection)) {
            $responseData['data']['rows'] = $collection;
            if ($conditionalQuery) {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM product_" . $this->di->getUser()->id . " WHERE " . $conditionalQuery . " LIMIT 0,1", "one");
            } else {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM product_" . $this->di->getUser()->id . " LIMIT 0,1", "one");
            }

            if (isset($countRecords['count'])) {
                $responseData['data']['count'] = $countRecords['count'];
            }
        }

        return $responseData;
    }

    public function getHeadersFromCsv($encodedFile)
    {
        $value = substr($encodedFile['encoded_file'], strpos($encodedFile['encoded_file'], ','), strlen($encodedFile['encoded_file']));
        $decodedFile = base64_decode($value);
        $ext = 'xlsx';
        if (strpos($encodedFile['encoded_file'], 'text/csv')) {
            $ext = 'csv';
        }

        $fileName = 'import_' . $this->di->getUser()->id . '_' . time();
        $path = BP . DS . 'var' . DS . 'import' . DS . $fileName . '.' . $ext;
        $handle = fopen($path, 'w');
        fwrite($handle, $decodedFile);
        $productData = [];
        switch ($ext) {
            case 'csv':
                $file = fopen($path, 'r');
                $productData[] = fgetcsv($file, 1024, ',');
                break;
            case 'xlsx':
                try {
                    $inputFileType = \PHPExcel_IOFactory::identify($path);
                    $objReader = \PHPExcel_IOFactory::createReader($inputFileType);
                    $objPHPExcel = $objReader->load($path);
                } catch (\Exception $e) {
                    return ['success' => false, 'message' => 'Error loading file "' . pathinfo($path, PATHINFO_BASENAME) . '": ' .
                        $e->getMessage()];
                }

                $sheet = $objPHPExcel->getSheet(0);
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, null, true, false);
                    $productData[] = $rowData[0];
                }

                break;
        }

        $warehouseName = [
            'warehouse' => $encodedFile['warehouse'],
            'type' => $encodedFile['type'],
        ];
        $mappedFileName = 'mapping_data_' . $this->di->getUser()->id . '_' . time();
        if (!file_exists(BP . DS . 'var' . DS . 'import' . DS . 'mapped-data')) {
            $oldmask = umask(0);
            mkdir(BP . DS . 'var' . DS . 'import' . DS . 'mapped-data', 0777, true);
            umask($oldmask);
        }

        if ($encodedFile['type'] == 'simple') {
            $mappingData = [];
            foreach ($productData[0] as $value) {
                $mappingData[$value] = [];
            }

            $warehouseName['mapped_data'] = $mappingData;
            $toReturnData = [
                'base_attributes' => $this->getBaseAttributes(),
                'source_attributes' => $productData[0],
                'suggestions' => $this->getMappingSuggestions($productData[0], $this->di->getUser()->getId(), 'file', 'connector'),
                'file_name' => $fileName . '.' . $ext,
                'mapped_file_name' => $mappedFileName,
            ];
        } else {
            $toReturnData = [
                'source_attributes' => $productData[0],
                'file_name' => $fileName . '.' . $ext,
                'mapped_file_name' => $mappedFileName,
            ];
        }

        $filePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $mappedFileName . '.php';
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($warehouseName, true) . ';');
        fclose($handle);
        return ['success' => true, 'data' => $toReturnData];
    }

    public function getBaseAttributes()
    {
        $prodAttrs = array_merge($this->getProductColumns()['data']['variant_columns'], [0 => 'source_product_id', 1 => 'title', 2 => 'short_description', 3 => 'long_description', 4 => 'variant_attributes']);
        return $prodAttrs;
    }

    public function getMappingSuggestions($sourceAttrs, $userId = false, $source = false, $target = 'connector')
    {
        return $this->di->getObjectManager()->get('importHelper')->getMappingSuggestions($sourceAttrs, $userId, $source, $target);
    }

    public function exportProduct($columns)
    {
        $historyArray = [];
        $userId = $this->di->getUser()->id;
        $historyArray['user_id'] = $userId;
        $historyArray['direction'] = 'export';
        $historyArray['errors'] = '';
        $historyArray['data_file'] = '';
        $historyArray['no_of_product'] = '';
        try {
            $userDb = $this->di->getObjectManager()->get('\App\Core\Components\MultipleDbManager')->getCurrentDb();
            $connection = $this->di->get($userDb);
            $containerColumns = $this->implodeArrays($columns['container_columns'], 'pc', 'container');
            $variantColumns = $this->implodeArrays($columns['variant_columns'], 'p', 'variant');
            $productsToExport = [];
            if ($columns['products_to_export'] == 'all') {
                $ids = ProductContainer::find(["columns" => "id"])->toArray();
                foreach ($ids as $value) {
                    $productsToExport[] = $value['id'];
                }
            } else {
                $productsToExport = $columns['products_to_export'];
            }

            $query1 = 'SELECT `pc`.id as `container_id`,' . $containerColumns . ', p.id as `variant_id`,' . $variantColumns . ' FROM `product_container_' . $userId . '` as `pc` LEFT JOIN `product_' . $userId . '` as `p` on (`p`.`product_id` = `pc`.`id`) WHERE pc.id IN (' . implode(',', $productsToExport) . ')';
            $query2 = "SELECT `pao`.`id`,`pa`.`code`,`pao`.`value`  FROM `product_attribute` as `pa` left join `product_attribute_option` as `pao` on `pao`.`attribute_id` = `pa`.`id` WHERE `merchant_id` = " . $userId . " AND `frontend_type` in ('dropdown','radio','checkbox')";
            $products = $connection->fetchAll($query1);
            $optionValues = $connection->fetchAll($query2);
            $formattedOptions = [];
            foreach ($optionValues as $value) {
                $formattedOptions[$value['code']][$value['id']] = $value['value'];
            }

            foreach ($products as $prodKey => $prod) {
                foreach ($prod as $key => $value) {
                    if (isset($formattedOptions[$key]) && $value != null) {
                        $products[$prodKey][$key] = $formattedOptions[$key][$value];
                    }
                }
            }

            $checkRedundancy = '';
            $containerCount = 0;
            foreach ($products as $prodKey => $value) {
                if ($checkRedundancy == $value['container_id']) {
                    $products[$prodKey]['container_id'] = null;
                    foreach ($value as $key => $cols) {
                        if (in_array($key, $columns['container_columns'])) {
                            $products[$prodKey][$key] = null;
                        }
                    }
                } else {
                    $checkRedundancy = $value['container_id'];
                    $containerCount++;
                }
            }

            $columns['container_columns'] = array_merge([0 => 'container_id'], $columns['container_columns']);
            $columns['variant_columns'] = array_merge([0 => 'variant_id'], $columns['variant_columns']);
            $outputCsv = [];
            $outputCsv[] = array_merge($columns['container_columns'], $columns['variant_columns']);
            foreach ($products as $value) {
                $outputCsv[] = $value;
            }

            $path = BP . DS . 'var' . DS . 'exports' . DS . $userId;
            if (!file_exists($path)) {
                $oldmask = umask(0);
                mkdir($path, 0777, true);
                umask($oldmask);
            }

            $fileName = 'export_product_' . time();
            if ($columns['export_to'] == 'csv') {
                $fvp = fopen($path . DS . $fileName . '.csv', 'w');
                foreach ($outputCsv as $fields) {
                    fputcsv($fvp, $fields);
                }

                fclose($fvp);
            } else {
                $excel = new \PHPExcel();
                $excel->setActiveSheetIndex(0);
                $excel->getActiveSheet()->setTitle('Product Information');
                $r = 1;
                foreach ($outputCsv as $fields) {
                    $i = 0;
                    foreach ($fields as $value) {
                        $column_length = $this->getNameFromNumber($i);
                        $excel->getActiveSheet()->setCellValue($column_length . $r, $value);
                        $i++;
                    }

                    $r++;
                }

                $naziv = $path . DS . $fileName . ".xlsx";
                $objWriter = new \PHPExcel_Writer_Excel2007($excel);
                $objWriter->save($naziv);
            }

            $date1 = new \DateTime('+5 min');
            $userDetails = [
                'user_id' => $userId,
                'fileName' => $fileName . '.' . $columns['export_to'],
                'extension' => $columns['export_to'],
                'exp' => $date1->getTimestamp(),
            ];
            $historyArray['data_file'] = $path . DS . $fileName . '.' . $columns['export_to'];
            $historyArray['no_of_product'] = $containerCount;
            $this->importExportHistory($historyArray);
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($userDetails, 'RS256', false);
            return ['success' => true, 'message' => 'Invalid Data', 'data' => $token];
        } catch (\Exception $e) {
            $historyArray['errors'] = $e->getMessage();
            $this->importExportHistory($historyArray);
            return ['success' => false, 'message' => 'Something went wrong', 'code' => 'something_wrong'];
        }
    }

    public function getConfigAttributes($sourceAttr)
    {
        $importFilePath = BP . DS . 'var' . DS . 'import' . DS . $sourceAttr['fileName'];
        $mappingFilePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $sourceAttr['mappedFileName'] . '.php';
        if (!file_exists($importFilePath) || !file_exists($mappingFilePath)) {
            return ['success' => false, 'message' => 'Please upload products first'];
        }
        $mappedData = require $mappingFilePath;
        $ext = explode('.', $sourceAttr['fileName']);
        $source = 'file';
        if (isset($sourceAttr['marketplace'])) {
            $source = $sourceAttr['marketplace'];
        }

        $productData = $this->getProductsFromFile($ext[1], $importFilePath);
        $configAttrIndex = array_search($sourceAttr['config_attr'], $productData[0]);
        $configAtrributes = '';
        for ($i = 1; $i < count($productData); $i++) {
            $configAtrributes .= $productData[$i][$configAttrIndex] . ',';
        }

        if (!is_array($configAtrributes)) {
            $configAtrributes = explode(',', $configAtrributes);
        }

        $configAttrs = [];
        foreach ($configAtrributes as $value) {
            if ($value != '' && $value != null && array_search($value, $configAttrs) === false) {
                $configAttrs[] = $value;
            }
        }

        $mappingData = [];
        foreach ($sourceAttr['source_attributes'] as $value) {
            if ($value == $sourceAttr['config_attr']) {
                $mappingData[$value] = [
                    0 => 'variant_attributes',
                ];
            } else {
                $mappingData[$value] = [];
            }
        }

        $baseVariantAttr = \App\Connector\Models\ProductAttribute::find(["merchant_id='" . $this->di->getUser()->id . "' AND is_config='1'", "column" => 'code'])->toArray();
        $baseConfigAttr = [];
        foreach ($baseVariantAttr as $value) {
            $baseConfigAttr[] = $value['code'];
        }

        $toReturnAttributes = [
            'source_config_attributes' => $this->getMappingSuggestions($configAttrs, $this->di->getUser()->getId(), $source, 'connector'),
            'base_config_attributes' => $baseConfigAttr,
        ];
        $mappedData['mapped_data'] = $mappingData;
        $mappedData['variant_attributes'] = $sourceAttr['config_attr'];
        $handle = fopen($mappingFilePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($mappedData, true) . ';');
        fclose($handle);
        return ['success' => true, 'data' => $toReturnAttributes];
    }

    public function saveConfigAttributeMapping($data)
    {
        $importFilePath = BP . DS . 'var' . DS . 'import' . DS . $data['fileName'];
        $mappingFilePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $data['mappedFileName'] . '.php';
        if (!file_exists($importFilePath) || !file_exists($mappingFilePath)) {
            return ['success' => false, 'code' => 'file_not_found', 'message' => 'Please upload file first'];
        }
        $mappingFileData = require $mappingFilePath;
        $source = 'file';
        if (isset($data['marketplace'])) {
            $source = $data['marketplace'];
        }

        $merchantId = $this->di->getUser()->id;
        $container = new ProductContainer();
        $variant = new Product();
        $metadata = $this->getModelsMetaData();
        $containerColumns = $metadata->getAttributes($container);
        $variantColumns = $metadata->getAttributes($variant);
        $contColumns = [];
        $varColumns = [];
        foreach ($containerColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'variant_attributes' || $columns == 'updated_at' || $columns == 'merchant_id' || $columns == 'type') {
                continue;
            }
            $contColumns[] = $columns;
        }

        foreach ($variantColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'updated_at' || $columns == 'product_id') {
                continue;
            }
            $varColumns[] = $columns;
        }

        $configAttr = ProductAttribute::find(["columns" => "code", "is_config='0' AND merchant_id='" . $merchantId . "'"])
            ->toArray();
        $allNonConfigAttr = [];
        $allNonConfigAttr[$mappingFileData['variant_attributes']] = '';
        foreach ($data['mapped_attr'] as $key => $value) {
            if (isset($value['base_attr'][0]) && $value['base_attr'][0]['id']) {
                $mappingFileData['mapped_data'][$value['source_attr']] = [
                    0 => $value['base_attr'][0]['id'],
                ];
            }

            $allNonConfigAttr[$value['source_attr']] = '';
        }

        $sourceNonConfigAttr = [];
        foreach ($mappingFileData['mapped_data'] as $key => $value) {
            if (count($value) == 0 || !isset($allNonConfigAttr[$key])) {
                $sourceNonConfigAttr[] = $key;
            }
        }

        $handle = fopen($mappingFilePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($mappingFileData, true) . ';');
        fclose($handle);
        $finalarray = [...$varColumns, ...$contColumns];
        foreach ($configAttr as $va) {
            $finalarray[] = $va['code'];
        }
        $nonConfigAttributes = [
            'source_non_config_attributes' => $sourceNonConfigAttr,
            'source_non_config_attr_suggestion' => $this->getMappingSuggestions($sourceNonConfigAttr, $this->di->getUser()->getId(), $source, 'connector'),
            'base_non_config_attributes' => $finalarray,
        ];
        return ['success' => true, 'message' => 'Success', 'data' => $nonConfigAttributes];
    }

    public function getProductsFromFile($ext, $importFilePath)
    {
        $productData = [];
        switch ($ext) {
            case 'csv':
                $file = fopen($importFilePath, 'r');
                while ($responseFile = fgetcsv($file, 1024, ',')) {
                    $productData[] = $responseFile;
                }

                break;
            case 'xlsx':
                try {
                    $inputFileType = \PHPExcel_IOFactory::identify($importFilePath);
                    $objReader = \PHPExcel_IOFactory::createReader($inputFileType);
                    $objPHPExcel = $objReader->load($importFilePath);
                } catch (\Exception $e) {
                    return ['success' => false, 'message' => 'Error loading file "' . pathinfo($importFilePath, PATHINFO_BASENAME) . '": ' .
                        $e->getMessage()];
                }

                $sheet = $objPHPExcel->getSheet(0);
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, null, true, false);
                    $productData[] = $rowData[0];
                }

                break;
            case 'php':
                $productData = require $importFilePath;
                break;
        }

        return $productData;
    }

    public function makeEntryInMarketplaceTable($productIds, $marketplace): void
    {
        $this->di->getObjectManager()->get('\App\\' . ucfirst($marketplace) . '\Components\MarketplaceTables')->makeEntryInMarketplaceTableForImport($productIds);
    }

    public function saveImages($imageUrl, $userId, $count)
    {
        if (!file_exists(BP . DS . 'public' . DS . 'media' . DS . 'temp')) {
            $oldmask = umask(0);
            mkdir(BP . DS . 'public' . DS . 'media' . DS . 'temp', 0777, true);
            umask($oldmask);
        }

        $imageInfo = pathinfo($imageUrl);
        $imageName = 'image_' . $count . '_' . time() . '.' . $imageInfo['extension'];
        $imgPath = BP . DS . 'public' . DS . 'media' . DS . 'temp' . DS . $imageName;
        $fp = fopen($imgPath, 'w+');
        $pathToReturn = 'media' . DS . 'product' . DS . $userId . DS . $imageName;
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $pathToReturn;
    }

    public function getProductColumns()
    {
        $container = new ProductContainer();
        $merchantId = $this->di->getUser()->id;
        $variant = new Product();
        $metadata = $this->getModelsMetaData();
        $containerColumns = $metadata->getAttributes($container);
        $variantColumns = $metadata->getAttributes($variant);
        $contColumns = [];
        $varColumns = [];
        foreach ($containerColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'updated_at' || $columns == 'merchant_id') {
                continue;
            }
            $contColumns[] = $columns;
        }

        foreach ($variantColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'updated_at' || $columns == 'additional_info') {
                continue;
            }
            $varColumns[] = $columns;
        }

        $marketplaceAttribute = ProductAttribute::find(["columns" => "code", "merchant_id='" . $merchantId . "' AND apply_on != 'base'"])
            ->toArray();
        foreach ($marketplaceAttribute as $val) {
            $varColumns[] = $val['code'];
        }

        $response = ['container_columns' => $contColumns, 'variant_columns' => $varColumns];
        return ["success" => true, "message" => "Columns of products", "data" => $response];
    }

    public function getProductFeeds($params)
    {
        if (isset($params['count']) && isset($params['activePage'])) {
            $filePath = BP . DS . 'app' . DS . 'code' . DS . 'connector' . DS . 'etc' . DS . 'history.php';
            $feedsData = [];
            if (file_exists($filePath)) {
                $feedsData = require $filePath;
            }

            $toSendData = [];
            $offset = $params['count'] * ($params['activePage'] - 1);
            $limit = ($params['count'] * ($params['activePage'] - 1)) + $params['count'];
            for ($i = $offset; $i < $limit + 1; $i++) {
                if (isset($feedsData[$i]) && $feedsData[$i]['merchant_id'] == $this->di->getUser()->id) {
                    $basePathLen = strlen('/opt/lampp/htdocs/phalcon/var/');
                    $feedsData[$i]['data_file'] = substr($feedsData[$i]['data_file'], strpos($feedsData[$i]['data_file'], '/opt/lampp/htdocs/phalcon/var/') + $basePathLen);
                    switch ($feedsData[$i]['direction']) {
                        case 'import':
                            $feedsData[$i]['errors'] = [
                                'error_link' => substr($feedsData[$i]['errors'], strpos($feedsData[$i]['errors'], '/opt/lampp/htdocs/phalcon/var/') + $basePathLen),
                            ];
                            break;
                        case 'export':
                            $feedsData[$i]['errors'] = [
                                'error_text' => $feedsData[$i]['errors'],
                            ];
                            break;
                    }

                    $toSendData[] = $feedsData[$i];
                }
            }

            return ['success' => true, 'message' => 'Product feeds', 'data' => ['rows' => $toSendData, 'count' => count($feedsData)]];
        }
        return ['success' => false, 'message' => 'Invalid request', 'code' => 'invalid_request'];
    }

    public function clearAllFeeds()
    {
        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'connector' . DS . 'etc' . DS . 'history.php';
        $feedsData = [];
        if (file_exists($filePath)) {
            $data = require $filePath;
            foreach ($data as $value) {
                if (file_exists($value['data_file'])) {
                    unlink($value['data_file']);
                }

                if (file_exists($value['errors'])) {
                    unlink($value['errors']);
                }
            }

            $handle = fopen($filePath, 'w+');
            fwrite($handle, '<?php return ' . var_export($feedsData, true) . ';');
            fclose($handle);
            return ['success' => true, 'message' => 'All feeds deleted', 'code' => 'all_feeds_deleted'];
        }
        return ['success' => false, 'message' => 'No feeds present', 'code' => 'no_feeds_present'];
    }

    public function getNameFromNumber($num)
    {
        $numeric = $num % 26;
        $letter = chr(65 + $numeric);
        $num2 = intval($num / 26);
        if ($num2 > 0) {
            return $this->getNameFromNumber($num2 - 1) . $letter;
        }
        return $letter;
    }

    public function importExportHistory($data)
    {
        $finalData = [];
        $finalData['date'] = time();
        $finalData['direction'] = isset($data['direction']) ? $data['direction'] : '';
        $finalData['merchant_id'] = $data['user_id'];
        $finalData['errors'] = isset($data['errors']) ? $data['errors'] : '';
        $finalData['no_of_product'] = isset($data['no_of_product']) ? $data['no_of_product'] : 0;
        $finalData['success'] = isset($data['success']) ? $data['success'] : 0;
        $finalData['data_file'] = isset($data['data_file']) ? $data['data_file'] : '';

        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'connector' . DS . 'etc' . DS . 'history.php';
        $oldData = [];
        if (file_exists($filePath)) {
            $oldData = require $filePath;
        }

        $oldData[] = $finalData;
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($oldData, true) . ';');
        fclose($handle);
        return count($oldData) - 1;
    }

    public function updateImportHistory($historyIndex, $errors, $title)
    {
        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'connector' . DS . 'etc' . DS . 'history.php';
        $data = [];
        if (file_exists($filePath)) {
            $data = require $filePath;
            if (isset($data[$historyIndex])) {
                if (empty($errors)) {
                    $data[$historyIndex]['success'] = $data[$historyIndex]['success'] + 1;
                } else {
                    $fvp = fopen($data[$historyIndex]['errors'], 'w');
                    fputcsv($fvp, [$title, implode(',', $errors)]);
                }
            }
        }

        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($data, true) . ';');
        fclose($handle);
        return count($data) - 1;
    }

    public function ifRequiredAttributeMapped($data)
    {
        $importFilePath = BP . DS . 'var' . DS . 'import' . DS . $data['fileName'];
        $mappingFilePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $data['mappedFileName'] . '.php';
        if (!file_exists($importFilePath) || !file_exists($mappingFilePath)) {
            return ['success' => false, 'code' => 'file_not_imported', 'message' => 'Please upload file first'];
        }
        $mappingFileData = require $mappingFilePath;
        $merchantId = $this->di->getUser()->id;
        $container = new ProductContainer();
        $variant = new Product();
        $metadata = $this->getModelsMetaData();
        $containerColumns = $metadata->getNotNullAttributes($container);
        $variantColumns = $metadata->getNotNullAttributes($variant);
        $contColumns = [];
        $varColumns = [];
        foreach ($containerColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'updated_at' || $columns == 'merchant_id' || $columns == 'type') {
                continue;
            }
            $contColumns[] = $columns;
        }

        foreach ($variantColumns as $columns) {
            if ($columns == 'created_at' || $columns == 'id' || $columns == 'updated_at' || $columns == 'product_id') {
                continue;
            }
            $varColumns[] = $columns;
        }

        $requiredattr = ProductAttribute::find(["columns" => "code", "is_required='1' AND merchant_id='" . $merchantId . "'"])
            ->toArray();
        $mappedAttr = [];
        $savedMappings = [];
        foreach ($data['mapped_attr'] as $value) {
            if (isset($value['base_attr'][0]) && $value['base_attr'][0]['id']) {
                $mappedAttr[] = $value['base_attr'][0]['id'];
                $mappingFileData['mapped_data'][$value['source_attr']] = [
                    0 => $value['base_attr'][0]['id'],
                ];
            }
        }

        $handle = fopen($mappingFilePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($mappingFileData, true) . ';');
        fclose($handle);
        $finalarray = [...$varColumns, ...$contColumns];
        foreach ($requiredattr as $va) {
            $finalarray[] = $va['code'];
        }

        $unMappedAttr = array_diff($finalarray, $mappedAttr);
        $unMappedAttr = array_values($unMappedAttr);

        $mappingData = [
            'unmapped_attr' => $unMappedAttr,
        ];
        return ['success' => true, 'message' => 'Success', 'data' => $mappingData];
    }

    public function saveUnmappedAttributes($data)
    {
        $importFilePath = BP . DS . 'var' . DS . 'import' . DS . $data['fileName'];
        $mappingFilePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $data['mappedFileName'] . '.php';
        if (!file_exists($importFilePath) || !file_exists($mappingFilePath)) {
            return ['success' => false, 'code' => 'file_not_imported', 'message' => 'Please upload file first'];
        }
        $mappingFileData = require $mappingFilePath;
        $defaultValues = [];
        foreach ($data['mapped_attr'] as $value) {
            if ($value['value']) {
                $defaultValues[$value['attribute']] = $value['value'];
            } else {
                $mappingFileData['mapped_data'][$value['sourceAttr']][] = $value['attribute'];
            }
        }

        $mappingFileData['default_values'] = $defaultValues;
        $source = 'file';
        if (isset($data['marketplace'])) {
            $source = $data['marketplace'];
        }

        $optionMapping = $this->getValuesOfAttributes($mappingFileData, $importFilePath, $source);
        $handle = fopen($mappingFilePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($mappingFileData, true) . ';');
        fclose($handle);
        return ['success' => true, 'message' => 'Mappings saved', 'data' => $optionMapping];
    }

    public function getValuesOfAttributes($mappingData, $importFilePath, $source)
    {
        $ext = explode('.', $importFilePath);
        $productData = $this->getProductsFromFile($ext[1], $importFilePath);
        $type = ['dropdown', 'checkbox', 'radio'];
        $optionTypeAttr = ProductAttribute::find(
            [
                "frontend_type IN ( {type:array}) AND merchant_id='{$this->di->getUser()->id}'",
                'bind' => [
                    'type' => $type,
                ],
                'column' => 'code',
            ]
        )->toArray();
        $valueAttrs = [];
        foreach ($optionTypeAttr as $value) {
            $valueAttrs[$value['code']] = $value['id'];
        }

        $valueMappingData = [];
        foreach ($mappingData['mapped_data'] as $sourceAttrKey => $sourceAttrValue) {
            foreach ($sourceAttrValue as $value) {
                if (isset($valueAttrs[$value])) {
                    $attrOptions = ProductAttributeOption::find(["attribute_id={$valueAttrs[$value]}", 'column' => 'value'])->toArray();
                    $sourceAttrOption = $this->getOptionsOfSourceAttribute($sourceAttrKey, $productData);
                    foreach ($attrOptions as $optionKey => $optionValue) {
                        $suggestion = $this->di->getObjectManager()->get('\App\Connector\Components\Suggestor')->getValueMappingSuggestion($source, 'connector', $sourceAttrKey, $value, $optionValue['value']);
                        if ($suggestion === false) {
                            $attrOptions[$optionKey] = [
                                'source_option' => '',
                                'base_option' => $optionValue['value'],
                            ];
                        } else {
                            $attrOptions[$optionKey] = [
                                'suggestion' => [
                                    0 => [
                                        'id' => $suggestion,
                                        'text' => $suggestion,
                                    ],
                                ],
                                'source_option' => $suggestion,
                                'base_option' => $optionValue['value'],
                            ];
                        }
                    }

                    $valueMappingData[] = [
                        'source_attribute' => $sourceAttrKey,
                        'base_attribute' => $value,
                        'source_attr_options' => $sourceAttrOption,
                        'base_attr_options' => $attrOptions,
                    ];
                }
            }
        }

        return $valueMappingData;
    }

    public function getOptionsOfSourceAttribute($field, $productData)
    {
        $fields = $productData[0];
        $attrIndex = array_search($field, $fields);
        $sourceAttrOptions = [];
        for ($i = 1; $i < count($productData); $i++) {
            if (array_search($productData[$i][$attrIndex], $sourceAttrOptions) === false) {
                $sourceAttrOptions[] = $productData[$i][$attrIndex];
            }
        }

        return $sourceAttrOptions;
    }

    public function saveValueMapping($data)
    {
        $importFilePath = BP . DS . 'var' . DS . 'import' . DS . $data['fileName'];
        $mappingFilePath = BP . DS . 'var' . DS . 'import' . DS . 'mapped-data' . DS . $data['mappedFileName'] . '.php';
        if (!file_exists($importFilePath) || !file_exists($mappingFilePath)) {
            return ['success' => false, 'code' => 'file_not_imported', 'message' => 'Please upload file first'];
        }
        $mappingFileData = require $mappingFilePath;
        foreach ($data['mapped_options'] as $value) {
            if (isset($mappingFileData['mapped_data'][$value['source_attribute']])) {
                foreach ($mappingFileData['mapped_data'][$value['source_attribute']] as $sourceMapKey => $sourceMapValue) {
                    if ($sourceMapValue == $value['base_attribute']) {
                        $mappingFileData['mapped_data'][$value['source_attribute']][$sourceMapKey] = [
                            'base_attr_value' => $sourceMapValue,
                            'value_mapping' => $value['base_attr_options'],
                        ];
                    }
                }
            }
        }

        $handle = fopen($mappingFilePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($mappingFileData, true) . ';');
        fclose($handle);
        return ['success' => true, 'message' => 'Mappings saved'];
    }

    // After deletion of varriant product it will delete the images from directory and delete the product from warehouse
    public function afterDelete(): void
    {
        $userDb = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($userDb);
        $variantValues = $this->toArray();
        unlink($variantValues['main_image']);
        $additionalImages = explode(',', $variantValues['additional_images']);
        foreach ($additionalImages as $img) {
            if ($img != '') {
                unlink($img);
            }
        }

        $warehouse_ids = (new Warehouse())->getUserWarehouseIds();
        $deleteQuery = "DELETE FROM `warehouse_product` WHERE `warehouse_id` IN (" . implode(',', $warehouse_ids) . ") AND `product_id` = '" . $this->id . "';";
        $connection->query($deleteQuery);
    }

    public function getJson($string)
    {
        $decoded = json_decode($string, true);
        return (json_last_error() == JSON_ERROR_NONE) ? $decoded : false;
    }

    public function prepareQuery($query)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', $query);
            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = trim($andConditionQuery, '()');
                $andConditions = explode('&&', $andConditionQuery);
                $andConditionSet = [];
                foreach ($andConditions as $andValue) {
                    $andConditionSet[] = $this->getAndConditions($andValue);
                }

                $orConditionQueries[] = [
                    '$and' => $andConditionSet,
                ];
            }

            $orConditionQueries = [
                '$or' => $orConditionQueries,
            ];
            return $orConditionQueries;
        }

        return false;
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim($andCondition);

        foreach ($conditions as $value) {
            if (strpos($andCondition, $value) !== false) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;
                $prefix = '';
                $valueOfProduct = trim(addslashes($keyValue[1]));
                if (trim($keyValue[0]) == 'price' ||
                    trim($keyValue[0]) == 'quantity') {
                    $isNumeric = true;
                    $valueOfProduct = (float) $valueOfProduct;
                }

                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getProductIdsByCollection((string) $valueOfProduct);
                    if ($productIds &&
                        count($productIds)) {
                        if ($value == '==' ||
                            $value == '%LIKE%') {
                            $preparedCondition['details.source_product_id'] = [
                                '$in' => $productIds,
                            ];
                        } elseif ($value == '!=' ||
                            $value == '!%LIKE%') {
                            $preparedCondition['details.source_product_id'] = [
                                '$nin' => $productIds,
                            ];
                        }
                    } else {
                        $preparedCondition['details.source_product_id'] = [
                            '$in' => $productIds,
                        ];
                    }

                    continue;
                }

                switch ($value) {
                    case '==':
                        if ($isNumeric) {
                            $preparedCondition[trim($keyValue[0])] = $valueOfProduct;
                        } else {
                            $preparedCondition[trim($keyValue[0])] = [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i',
                            ];
                        }

                        break;
                    case '!=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$not' => [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i',
                            ],
                        ];
                        break;
                    case '%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => ".*" . $valueOfProduct . ".*",
                            '$options' => 'i',
                        ];
                        break;
                    case '!%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => "^((?!" . $valueOfProduct . ").)*$",
                            '$options' => 'i',
                        ];
                        break;
                    case '>':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gt' => (float) $valueOfProduct,
                        ];
                        break;
                    case '<':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lt' => (float) $valueOfProduct,
                        ];
                        break;
                    case '>=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gte' => (float) $valueOfProduct,
                        ];
                        break;
                    case '<=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lte' => (float) $valueOfProduct,
                        ];
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function getProductCountByQuery($marketplace, $userId = false, $shopId = false, $profileId = false, $unwindVariants = true)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if ($profileId) {
            $collection = $this->getCollectionForTable('profiles');
            $profile = $collection->findOne([
                "profile_id" => (int) $profileId,
            ]);
            if ($profile) {
                $filterQuery = $profile->query;
                $marketplace = $profile->source;
            } else {
                $filterQuery = '(price > -1)';
            }
        } else {
            $filterQuery = '(price > -1)';
        }

        $customizedFilterQuery = $this->prepareQuery($filterQuery);
        if ($customizedFilterQuery) {
            $finalQuery = [];
            if ($unwindVariants) {
                $finalQuery[] = [
                    '$unwind' => '$variants',
                ];
            }

            $finalQuery[] = [
                '$match' => $customizedFilterQuery,
            ];
            $finalQuery[] = [
                '$count' => 'count',
            ];
            $collection = $this->getCollectionForTable('product_container_' . $userId);
            $response = $collection->aggregate($finalQuery);
            $response = $response->toArray();
            $count = isset($response[0]) ? $response[0]['count'] : 0;
            if ($profileId) {
                return [
                    'count' => $count,
                    'query' => $filterQuery,
                    'marketplace' => $marketplace,
                ];
            }

            return $count;
        }

        return false;
    }

    public function getProductsByQuery($productQueryDetails, $userId = false, $shopId = false, $unwindVariants = true)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $sendCount = isset($productQueryDetails['sendCount']) ? true : false;
        $filterQuery = isset($productQueryDetails['query']) ? $productQueryDetails['query'] : '';
        $filterQuery = $this->prepareQuery($filterQuery);
        if ($shopId) {
            $filterQuery = [
                '$and' => [
                    [
                        "store_id" => (string) $shopId,
                    ],
                    $filterQuery,
                ],
            ];
        }

        $filterQuery = [
            '$and' => [
                [
                    "user_id" => $userId,
                ],
                $filterQuery,
            ],
        ];
        if ($filterQuery) {

            $finalQuery[] = [
                '$match' => $filterQuery,
            ];
            if (isset($productQueryDetails['activePage']) &&
                isset($productQueryDetails['count'])) {
                $limit = $productQueryDetails['count'] * $productQueryDetails['activePage'];
                $skip = $limit - $productQueryDetails['count'];
                $finalQuery[] = [
                    '$limit' => $limit,
                ];
                $finalQuery[] = [
                    '$skip' => $skip,
                ];
            }

            $collection = $this->getCollectionForTable('product_container');
            if (!$sendCount) {
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $response = $collection->aggregate($finalQuery, $options);
                $response = $response->toArray();
                $selectedProducts = [];
                foreach ($response as $productData) {
                    $storeId = false;
                    $variantAttrs = [];
                    if (isset($productData['variant_attributes'])) {
                        $variantAttrs = $productData['variant_attributes'];
                    } else if (isset($productData['variant_attribute'])) {
                        $variantAttrs = $productData['variant_attribute'];
                    }

                    $selectedProducts[] = [
                        '_id' => $productData['_id'],
                        'details' => $productData['details'],
                        'variants' => $productData['variants'],
                        'variant_attributes' => $variantAttrs,
                        'shop_id' => $storeId,
                        'updated_at' => $productData['updated_at'],
                        'created_at' => $productData['created_at'],
                        'source_marketplace' => $productData['source_marketplace'],
                    ];

                }

                return ['success' => true, 'data' => $selectedProducts];
            }
            $finalQuery[] = [
                '$count' => 'count',
            ];
            $response = $collection->aggregate($finalQuery);
            $response = $response->toArray();
            $count = $response[0]['count'];
            if (!$count) {
                $count = 0;
            }
            return ['success' => true, 'data' => $count];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function getAttributesByProductQuery($productQueryDetails, $userId = false)
    {

        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $marketplace = isset($productQueryDetails['marketplace']) ? $productQueryDetails['marketplace'] : '';
        $filterQuery = isset($productQueryDetails['query']) ? $productQueryDetails['query'] : '';
        if ($filterQuery != '') {
            $filterQuery = ltrim($filterQuery, "||");
            $filterQuery = ltrim($filterQuery, " ||");
            $filterQuery = ltrim($filterQuery, "&&");
            $filterQuery = ltrim($filterQuery, " &&");
        }

        $filterQuery = $this->prepareQuery($filterQuery);
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $filterQuery,
                ],
                [
                    '$limit' => 1000,
                ],
                [
                    '$skip' => 0,
                ],
                [
                    '$lookup' => [
                        'from' => $marketplace . '_product_' . $userId,
                        'localField' => "_id",
                        'foreignField' => "_id",
                        'as' => "fromItems",
                    ],
                ],
            ];
            $collection = $this->getCollectionForTable('product_container_' . $userId);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $response = $response->toArray();
            $selectedProductAttributes = [];
            $fixedAttributes = [

                [
                    'code' => 'handle',
                    'title' => 'Handle',
                    'required' => 0,
                ],
                [
                    'code' => 'product_type',
                    'title' => 'Product Type',
                    'required' => 0,
                ],
                [
                    'code' => 'type',
                    'title' => 'type',
                    'required' => 0
                ],
                [
                    'code' => 'title',
                    'title' => 'title',
                    'required' => 0
                ],
                [
                    'code' => 'brand',
                    'title' => 'brand',
                    'required' => 0
                ],
                [
                    'code' => 'description',
                    'title' => 'description',
                    'required' => 0
                ],
                [
                    'code' => 'tags',
                    'title' => 'tags',
                    'required' => 0
                ],
                [
                    'code' => 'collection',
                    'title' => 'collection',
                    'required' => 0
                ],
                [
                    'code' => 'position',
                    'title' => 'position',
                    'required' => 0
                ],
                [
                    'code' => 'sku',
                    'title' => 'sku',
                    'required' => 0
                ],
                [
                    'code' => 'price',
                    'title' => 'price',
                    'required' => 0
                ],
                [
                    'code' => 'quantity',
                    'title' => 'quantity',
                    'required' => 0
                ],
                [
                    'code' => 'weight',
                    'title' => 'weight',
                    'required' => 0
                ],
                [
                    'code' => 'weight_unit',
                    'title' => 'weight_unit',
                    'required' => 0
                ],
                [
                    'code' => 'grams',
                    'title' => 'grams',
                    'required' => 0
                ],
                [
                    'code' => 'barcode',
                    'title' => 'barcode',
                    'required' => 0
                ],
                [
                    'code' => 'inventory_policy',
                    'title' => 'inventory_policy',
                    'required' => 0
                ],
                [
                    'code' => 'taxable',
                    'title' => 'taxable',
                    'required' => 0
                ],
                [
                    'code' => 'fulfillment_service',
                    'title' => 'fulfillment_service',
                    'required' => 0
                ],
                [
                    'code' => 'inventory_item_id',
                    'title' => 'inventory_item_id',
                    'required' => 0
                ],
                [
                    'code' => 'inventory_tracked',
                    'title' => 'inventory_tracked',
                    'required' => 0
                ],
                [
                    'code' => 'requires_shipping',
                    'title' => 'requires_shipping',
                    'required' => 0
                ],

                [
                    'code' => 'is_imported',
                    'title' => 'is_imported',
                    'required' => 0
                ],
                [
                    'code' => 'visibility',
                    'title' => 'visibility',
                    'required' => 0
                ]
            ];

            if ($userId == '60d5d70cfd78c76e34776e12') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '60eccb2dab21ed6f1f66248f') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '610802aad6b67135c06a4876') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '61113902f426e418d565aed6') {
                $fixedAttributes[] = [
                    'code' => 'Type',
                    'title' => 'Type',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Crystal',
                    'title' => 'Crystal',
                    'required' => 0
                ];
            }

            if ($userId == '60ee9327c1938e253f799085') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'QtyU+FF0E',
                    'title' => 'Qty.',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Quantity',
                    'title' => 'Quantity',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'QTYU+FF0E',
                    'title' => 'QTY.',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'QTYU+FF0E 1',
                    'title' => 'QTY. 1',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Box of 25',
                    'title' => 'Box of 25',
                    'required' => 0
                ];
            }

            if ($userId == '61185357d4fc9a531034983d') {
                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '6112e6666f529d69025eb1c0') {
                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '61182f2ad4fc9a5310349827') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '6116a3f33715e60b0013662f') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'US Shoe Size (Men\'s)',
                    'title' => 'US Shoe Size (Men\'s)',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Dress Shirt Size',
                    'title' => 'Dress Shirt Size',
                    'required' => 0
                ];
            }

            if ($userId == '6116766b6bfb371e3d214126') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];
            }

            if ($userId == '61130a0d6f529d69025eb1c4') {
                $fixedAttributes[] = [
                    'code' => 'variant_title',
                    'title' => 'Variant Title',
                    'required' => 0
                ];
            }

            if ($userId == '6116cad63715e60b00136653') {
                $fixedAttributes[] = [
                    'code' => 'Capsules',
                    'title' => 'Capsules',
                    'required' => 0
                ];
            }

            if ($userId == '6121b8b820035f7d7c1898c7') {
                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];
            }

            if ($userId == '61206af1457d0925ed705d17') {
                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];
            }

            if ($userId == '611cbdc45a71ee43e7008871') {
                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];
            }

            if ($userId == '612353f01ff1520b270e0d30') {
                $fixedAttributes[] = [
                    'code' => 'Style',
                    'title' => 'Style',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Colour',
                    'title' => 'Colour',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Color',
                    'title' => 'Color',
                    'required' => 0
                ];

                $fixedAttributes[] = [
                    'code' => 'Size',
                    'title' => 'Size',
                    'required' => 0
                ];
            }

            foreach ($response as $value) {
                $productData = $value;
                $selectedProductAttributes = array_merge($selectedProductAttributes, $this->getAttributesFromProduct($productData['variants'][0], $marketplace));
            }

            $selectedProductAttributes = array_merge(array_values($selectedProductAttributes), $fixedAttributes);
            return ['success' => true, 'data' => $selectedProductAttributes];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function getAttributesFromProduct($productData, $marketplace)
    {
        $attributes = [];
        foreach ($productData as $key => $value) {
            $attr = $this->getAttributeDetails($key, $marketplace);
            if ($attr) {
                $attributes[$key] = $attr;
            }
        }

        return $attributes;
    }

    public function getAttributeDetails($variantAttribute, $marketplace)
    {
        try {
            if (strpos($variantAttribute, "'") !== false) {
                $variantAttribute = addslashes($variantAttribute);
            }

            $attribute = ProductAttribute::findFirst(
                [
                    "code='{$variantAttribute}' AND apply_on IN ({marketplace:array})",
                    "bind" => [
                        "marketplace" => [
                            $marketplace,
                            'base',
                        ],
                    ],
                ]);
            if ($attribute) {
                $attributeDetails = [
                    'code' => $attribute->code,
                    'title' => $attribute->label,
                    'required' => $attribute->is_required,
                ];
                if ($attribute->frontend_type == 'dropdown' ||
                    $attribute->frontend_type == 'checkbox') {
                    $attributeId = $attribute->id;
                    $options = ProductAttributeOption::find(["attribute_id='{$attributeId}'"]);
                    $optionValues = [];
                    foreach ($options as $value) {
                        $optionValues[] = [
                            'code' => $value->value,
                            'value' => $value->value,
                        ];
                    }

                    $attributeDetails['values'] = $optionValues;
                }
            } else {
                $attributeDetails = [
                    'code' => $variantAttribute,
                    'title' => ucfirst(str_replace('_', ' ', $variantAttribute)),
                    'required' => true,
                ];
            }

            return $attributeDetails;
        } catch (\Exception) {
            return false;
        }
    }

    public function getCollectionForTable($table)
    {

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongo->setSource($table);

        $collection = $mongo->getCollection();
        return $collection;
    }

    public function getConfigurableAttributes($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $configurableAttributes = [];
        $collection = $this->getCollectionForTable('product_configurable_attributes');
        $response = $collection->findOne([
            'user_id' => $userId,
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($response &&
            count($response)) {
            $configurableAttributes = $response['configurable_attributes'];
        } else {
            $collection = $this->getCollectionForTable('product_container_' . $userId);
            $aggregateCountQuery = [
                [
                    '$match' => [
                        'variant_attributes' => [
                            '$ne' => [],
                        ],
                    ],
                ],
                [
                    '$count' => 'count',
                ],
            ];
            $countResponse = $collection->aggregate($aggregateCountQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            $countResponse = $countResponse->toArray();
            $productsCount = count($countResponse) ? $countResponse[0]['count'] : 0;
            if ($productsCount > 0) {
                $totalIterations = ceil($productsCount / 10000);
                $chunkSize = 10000;
                for ($i = 0; $i < $totalIterations; $i++) {
                    $aggregateQuery = [
                        [
                            '$match' => [
                                'variant_attributes' => [
                                    '$ne' => [],
                                ],
                            ],
                        ],
                        [
                            '$project' => [
                                'variant_attributes' => 1,
                            ],
                        ],
                        [
                            '$limit' => $chunkSize,
                        ],
                        [
                            '$skip' => $i * $chunkSize,
                        ],
                    ];
                    $response = $collection->aggregate($aggregateQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                    $response = $response->toArray();
                    if (count($response)) {
                        foreach ($response as $prod) {
                            foreach ($prod['variant_attributes'] as $variantAttr) {
                                if (!in_array($variantAttr, $configurableAttributes)) {
                                    $configurableAttributes[] = $variantAttr;
                                }
                            }
                        }
                    }

                    unset($response);
                }
            }

            if ($configurableAttributes !== []) {
                $configAtrributes = [
                    'user_id' => $userId,
                    'configurable_attributes' => $configurableAttributes,
                ];
                $collection = $this->getCollectionForTable('product_configurable_attributes');
                $collection->insertMany([$configAtrributes]);
            }
        }

        return ['success' => true, 'data' => $configurableAttributes];
    }

}
