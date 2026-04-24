<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
use IteratorIterator;
use Exception;
// use Phalcon\Mvc\Controller;
// use Phalcon\Di;
class CsvHelper extends Base
{
    public function getProductDeatils($data)
    {
        $userId = $this->di->getUser()->id;

        // die($this->di->getUser()->id);
        // die($this->di->getConfig()->base_url_frontend);
        // die(json_encode($data));\App\Connector\Models\ProductContainer
        $ProductController = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');
        $dataCount = $ProductController->getProductsCount($data);
        $count =$dataCount['data']['count'];
        // die($count."sdf");
        // die($count."dsf");

        $basePath=BP.DS.'var/log/UserProductCSV/';

        if (!file_exists($basePath)) {
            mkdir($basePath);
            chmod($basePath, 0777);
        }

        $path=$basePath."bulk_edit_".$userId.".csv";

        if (!file_exists($path)) {
            $file =fopen($path, 'w');
            chmod($path, 0777);
        } else {
            $file =fopen($path, 'w');
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $notifications = $mongo->getCollectionForTable('notifications');
        $notifications->deleteMany(['user_id'=>$userId,'message'=>'CSV Export Completed']);

        // die("no done");


        $user_detail= $collection->findOne(['user_id'=>$userId]);

        // die(json_encode($user_detail));

        // die(json_encode($user_detail));
        // echo "<pre>";
        // die(json_encode($user_detail['CSVcolumn']));

        $params=$data;
        // $toEditUpdate= $data['editUpdate'];
        // print_r(json_encode($toEditUpdate));
        // echo "<br>chnages <br>";
        // if (isset($toEditUpdate['source_product_id'])) {
        //     unset($toEditUpdate['source_product_id']);
        // }

        // die(json_encode($toEditUpdate));
        $mandotaryField=["title","sku", "description",'source_product_id','container_id','brand','main_image'];
        if (isset($user_detail['CSVcolumn'])) {
            $line=json_decode((string) $user_detail['CSVcolumn']);
            foreach ($mandotaryField as $valueMan) {
                if (!(in_array($valueMan, $line))) {
                    $line[]=$valueMan;
                }
            }
        } else {
            $line=$mandotaryField;
        }

        $len= count($line);

        // print_r(json_encode($line));
        // die;

        $variantLine=["type","option1 name","option1 value",
        "option2 name","option2 value","option3 name","option3 value"];

        foreach ($line as $ke=>$va) {
            if (in_array($va, $variantLine) || $va=='') {
                unset($line[$ke]);
            }
        }

        $line1=array_unique(array_merge($line, $variantLine));

        fputcsv($file, $line1);
        // die("df");
        $feed_id= $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->setQueuedTasks('CSV Export in progress', '', $userId);
        $CSVFile = [
            'file_path' => $path
        ];
        $token = $this->di->getObjectManager()
            ->get('App\Core\Components\Helper')
            ->getJwtToken($CSVFile, 'RS256', false);
        $base_url=$this->di->getConfig()->base_url_frontend ?? 'https://home.connector.sellernext.com/';
        $url = $base_url . 'frontend/csv/downloadCSV?file_token=' . $token;

        $appTag = $this->di->getAppCode()->getAppTag();
        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Frontend\Components\CsvHelper::class,
            'method' => 'ProductWriteCSVSQS',
            'queue_name' => 'bulk_edit_1',
            'app_tag'=>$appTag,
            'user_id'=>$userId,
            'app_code'=>$data['app_code'],
            'data' => [
                'url'=>$url,
                'totalCount'=>$count,
                'feed_id'=>$feed_id,
                'cursor' => 1,
                'skip'=>0,
                'path'=>$path,
                'limit'=>200,
                'params'=>$params,
                'target_marketplace'=>$data['marketplace'],
                'columns'=>$line,
                'variant_line'=>$variantLine
            ]
        ];
        // die("sdf");
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);
        // $url = 'http://amazon-sales-channel.demo.cedcommerce.com/home/public/frontend/csv/downloadCSV?file_token=' . $token;
        return $url;
    }

    public function createLine($columns, $value, $toEditUpdate, $type, $variantAttributes, $parent=[])
    {
        $line=[];
        foreach ($columns as $column) {
            $newCol=true;
            if (isset($value[$column])) {
                $line[]=$value[$column];
                $newCol=false;
            } else {
                if ($type=='child') {
                    if ($column=='title' || $column=='description' || $column=='main_image' || $column=='brand') {
                        $line[]=$parent[$column];
                    } else {
                        $line[]='';
                    }
                } else {
                    $line[]='';
                }
            }
        }

        $line[]=$type;
        $i=0;
        if ($type=="child") {
            $variant_value=explode('/', (string) $value['variant_title']);
            foreach ($variantAttributes as $var_attr) {
                $line[]=$var_attr;
                $line[]=$variant_value[$i];
                $i++;
            }

            // if (isset($value['variant_attributes'])) {
            //     foreach ($value['variant_attributes'] as $variant_attributes) {
            //         $i++;
            //         $line[]=$variant_attributes;
            //         $line[]=$value[$variant_attributes] ?? "";
            //     }
            // }
        }

        for ($j=1;$j<=3-$i;$j++) {
            $line[]="";
            $line[]="";
        }

        return $line;
    }

    public function ProductWriteCSVSQS($sqsData)
    {
        $path=$sqsData['data']['path'];
        // die($path."path");
        $totalDataRead=$sqsData['data']['totalDataRead']?? 0;
        $totalCount=$sqsData['data']['totalCount'];
        $userId = $this->di->getUser()->id;
        $file =fopen($path, 'a');
        $params=$sqsData['data']['params'];
        $variantLine=$sqsData['data']['variant_line'];
        $toEditUpdate="";
        // $toEditUpdate=$params['editUpdate'];
        // $columnToEdit=array_keys($toEditUpdate);
        $columns=$sqsData['data']['columns'];
        $ProductController = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');
        $row = $ProductController->getProducts($params);
        $rowsToWrite= $row['data']['rows'];
        $next =$row['data']['next'];
        print_r($params);
        // die(print_r($rowsToWrite));
        foreach ($rowsToWrite  as $value) {
            $line=$this->createLine($columns, $value, $toEditUpdate, "main", $variantLine);
            fputcsv($file, $line);
            if ($value['type']=="variation" && isset($value['variants'])) {
                foreach ($value['variants'] as $variant) {
                    $line=[];
                    $line=$this->createLine($columns, $variant, $toEditUpdate, "child", $value['variant_attributes'], $value);
                    fputcsv($file, $line);
                }
            }
        }
        ;
        $totalDataRead=$totalDataRead+$row['data']['current_count'];

        $params['activePage']=$params['activePage']+1;

        // $params['next']=$next;
        // if(isset($params['activePage'])){
        //     unset($params['activePage']);
        // }

        if ($next==null) {
            $message="CSV Export Completed";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success', $sqsData['data']['url']);
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $progress=round(($row['data']['current_count']/$totalCount)*100, "3");
        echo $progress;
        // return true;
        $sqsData['data']['totalDataRead']=$totalDataRead;
        $message="CSV Export in progress";
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->updateFeedProgress($sqsData['data']['feed_id'], $progress, $message);
        $sqsData['data']['cursor']++;
        $sqsData['data']['params']=$params;
        $this->pushToQueue($sqsData);
    }

    public function pushToQueue($sqsData)
    {
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        return $rmqHelper->createQueue($sqsData['queue_name'], $sqsData);
    }

    public function BulkEdit($sqsData)
    {
        // will help to determine the char need to skip
        $skip_character = $sqsData['skip_character'] ?? 0;
        // how many line need to Read
        $row_chunk = $sqsData['chunk'] ?? 2000;
        $scaned = $sqsData['scanned'] ?? 0;
        $myFile=$sqsData['data']['path'];
        $editUpdate=$sqsData['data']['editUpdate'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("amazon_product_container");

        $mongoCollection = $mongo->getCollection();

        $bulkOpArray = [];
        // used to break the loop done
        $i = 0;
        $dontAdd=["title","description","LONGSKU","main_image","type","option1 name","option1 value",
        "option2 name","option2 value","option3 name","option3 value"];

        $dontAddChild=["title","description","main_image","type","option1 name","option1 value",
        "option2 name","option2 value","option3 name","option3 value"];
        if (($handle = fopen($myFile, 'r')) !== false) {
            // get the first row, which contains the column-titles (if necessary)
            // verfiy the header before you start the work on Vidaxl different CSV
            $header = fgetcsv($handle);
            // it will help you the Skip the charater which are already been used
            // echo "<br>".$skip_character."<br>";
            fseek($handle, $skip_character);
            // loop through the file line-by-line
            // echo "<br>good to go";
            $productArrayData = [];
            while (($product = fgetcsv($handle)) !== false && $i < $row_chunk) {
                // Give the The Row Data, in Array
                $prepareProductArr=[];
                foreach ($product as $key=>$eachEle) {
                    // if($header[$key]=='type')
                    if (!(in_array($header[$key], $dontAdd)) && $header[$key]) {
                        $prepareProductArr=$prepareProductArr+ [
                        $header[$key]=>$eachEle
                    ];
                    }
                }

                print_r($prepareProductArr);
                $prepareProductArr=$prepareProductArr+['whereUpdatedFrom'=>'csv1'];
                // $prepareProductArr =$prepareProductArr+$editUpdate[$prepareProductArr['source_product_id']] ?? [];
                //update if extra info given



                $bulkOpArray[] = [
                    'updateOne' => [
                        ['user_id'=>$sqsData['user_id'],'source_product_id' => $prepareProductArr['source_product_id']],
                        [
                            '$set' => $prepareProductArr
                        ],
                    ],
                ];
                // Will Give the number of Character read till now
                $skip_character = ftell($handle);
                unset($product);
                $i++;
                $scaned++;
            }

            $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);
            fclose($handle);
        }

        $progress=round($i/$sqsData['data']['count']*100, '3');
        if(isset($sqsData['totalProgress'])){
            $totalProgress = $sqsData['totalProgress']+ $progress;
        }else{
            $totalProgress =$progress; 
        }

        if ($i < $row_chunk || $totalProgress==100) {
            echo "completed";
            $message="CSV Import Completed";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success');
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $sqsData['skip_character'] = $skip_character;
        $sqsData['chunk'] = $row_chunk;
        $sqsData['scanned']=$scaned;
        $sqsData['totalProgress']=$totalProgress;
        $sqsData['data']['cursor']++;
        echo  $sqsData['data']['cursor'];
        $message="CSV Import Progress";
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->updateFeedProgress($sqsData['data']['feed_id'], $progress, $message);
        $this->pushToQueue($sqsData);
    }

    public function importCSVAndUpdate($params)
    {
        $userId = $this->di->getUser()->id;
        $appTag = $this->di->getAppCode()->getAppTag();
        $feed_id= $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->setQueuedTasks('CSV Import in progress', '', $userId);
        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Frontend\Components\CsvHelper::class,
            'method' => 'BulkEdit',
            'queue_name' => 'bulk_edit_2',
            'app_tag'=>$appTag,
            'user_id'=>$userId,
            'data' => [
                'path'=>$params['path'].$params['name'],
                'feed_id'=>$feed_id,
                'cursor' => 0,
                'skip'=>0,
                'limit'=>200,
                'count'=>$params['count'],
                'editUpdate'=>$params['editUpdate']
            ]
        ];
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);
        return true;
        die();
    }

    public function getProducts($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getPhpCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? ProductContainer::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode((string) $params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        $aggregation[] = [
            '$sort' => ['visibility' => 1]
        ];

        $aggregation[] = [
            '$sort' => ['container_id' => 1]
        ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        $select_fields = ['user_id', 'sku', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'marketplace', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes'];

        $_group = ['_id' => '$container_id'];
        foreach ($select_fields as $field) {
            $_group[$field] = ['$first' => '$' . $field];
        }

        $aggregation[] = [
            '$group' => $_group
        ];

        $aggregation[] = [
            '$sort' => ['_id' => 1]
        ];

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];

        try {
            // use this below code get to slow down
            //TODO: TEST Needed here
            // $rows = $collection->aggregate($aggregation)->toArray();
            // print_r($aggregation);
            // die;
            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

            $cursor = $collection->aggregate($aggregation, $options);
            $it = new IteratorIterator($cursor);
            $it->rewind();
            // Very important
            $rows = [];
            $distinctContainerIds = [];

            while ($limit > 0 && $doc = $it->current()) {
                if (!in_array((string)$doc['container_id'], $distinctContainerIds)) {
                    $distinctContainerIds[] = (string)$doc['container_id'];

                    $rows[$doc['container_id']] = [
                        'user_id'           => $doc['user_id'],
                        'container_id'      => $doc['container_id'],
                        'main_image'        => $doc['main_image'],
                        'title'             => $doc['title'],
                        'additional_images' => $doc['additional_images'],
                        'sku'               =>$doc['sku'],
                        // 'type'              => $doc['type'],
                        'brand'             => $doc['brand'],
                        'description'       => $doc['description'] ?? '',
                        'product_type'      => $doc['product_type'],
                        // 'visibility'        => $doc['visibility'],
                        'app_codes'         => $doc['app_codes'],
                        'variant_attributes' => $doc['variant_attributes']
                    ];
                    // $rows[$doc['container_id']]=$doc;

                    if (!empty($doc['profile'])) {
                        $rows[$doc['container_id']]['profile'] = $doc['profile'];
                    }

                    if (!empty($doc['marketplace'])) {
                        $rows[$doc['container_id']]['marketplace'] = $doc['marketplace'];
                    }

                    $limit--;
                }

                $it->next();
            }

            $productStatuses = $this->prepareProductsStatus($distinctContainerIds);
            foreach ($rows as $c_id => $row) {
                $rows[$c_id]['variants'] = $productStatuses[$c_id]['variations'] ?? [];
                $rows[$c_id]['source_product_id'] = $productStatuses[$c_id]['container_source_product_id'];
                $rows[$c_id]['type'] = $productStatuses[$c_id]['container_type'];
                $rows[$c_id]['visibility'] = $productStatuses[$c_id]['container_visibility'];
            }

            $rows = array_values($rows);

            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // 'data' => $aggregation
            ];
        }

        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null
            ]
        ];

        return $responseData;
    }

    public function getProductsCount($params)
    {
        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getPhpCollection();

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        // $aggregation[] = [
        //     '$graphLookup' => [
        //         "from"              => "product_container",
        //         "startWith"         => '$container_id',
        //         "connectFromField"  => "container_id",
        //         "connectToField"    => "group_id",
        //         "as"                => "variants",
        //         "maxDepth"          => 1
        //     ]
        // ];

        // $aggregation[] = [
        //     '$match' => [
        //         '$or' => [
        //             ['$and' => [
        //                 ['type' => 'variation'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //             ['$and' => [
        //                 ['type' => 'simple'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //         ],
        //     ],
        // ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params, 'productCount'));

        $aggregation[] = [
            '$group' => [
                '_id' => '$container_id'
            ]
        ];

        $aggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($aggregation)->toArray();
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()/*,'data' => $aggregation*/];
        }

        $responseData = [
            'success' => true,
            'query' => $aggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }

    private function buildAggregateQuery($params, $callType = 'getProduct'): array
    {
        $productOnly = false;
        $aggregation = [];
        $andQuery = [];
        $orQuery = [];
        $filterType = '$and';

        if (isset($params['productOnly'])) {
            $productOnly = $params['productOnly'] === "true";
        }

        if (isset($params['filterType'])) {
            $filterType = '$' . $params['filterType'];
        }

        if (isset($params['filter']) || isset($params['search'])) {
            // $andQuery = self::search($params);
            $andQuery = ProductContainer::search($params);
        }

        if (isset($params['or_filter'])) {
            // $orQuery = self::search(['filter' => $params['or_filter']]);
            $orQuery = ProductContainer::search(['filter' => $params['or_filter']]);
            $temp = [];
            foreach ($orQuery as $optionKey => $orQueryValue) {
                $temp[] = [
                    $optionKey => $orQueryValue,
                ];
            }

            $orQuery = $temp;
        }

        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode((string) $params['next']), true);
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$lt' => $params['prev'],
                ]],
            ];
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => ['container_id' => [
                    '$in' => $params['container_ids']
                ]],
            ];
        }

        if (count($andQuery)) {
            $aggregation[] = ['$match' => [
                $filterType => [
                    $andQuery,
                ],
            ]];
        }

        if ($orQuery !== []) {
            $aggregation[] = ['$match' => [
                '$or' => $orQuery,
            ]];
        }

        return $aggregation;
    }
}
