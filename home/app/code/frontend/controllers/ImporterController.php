<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;
use App\Frontend\Components\Importer_Helper;
class ImporterController extends BaseController
{
    public function addNewsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->newsFromData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function deleteNewsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->deleteData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function addBlogAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->addBlogs($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function deleteBlogAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->deleteBlogData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function creditsGetDataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->getCredits($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function updateCreditDataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->getUpdateCredits($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function reviewRatingDataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody =[];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->reviewRating($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function fetchReviewRatingDataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $user_id =$this->di->getUser()->id;

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->fetchReviewRating($user_id);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function neverAskAgainForRatingAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody =[];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Importer_Helper::class)->neverAskAgainReviewSystem($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function getCollectionShopifyAction(){
        $user_id = $this->di->getUser()->id;
        $shop = $this->di->getObjectManager()->get('\App\Shopify\Models\Shop\Details');
        $shop_data = $shop::findFirst(
            ['conditions' => 'user_id="' . $user_id . '"']
        )->toArray();
        $shop_id = $shop_data['id'];
        $shop_url = $shop_data['shop_url'];
        $result = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getCollections($shop_url);
        $result_location = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getLocations($shop_url);
//        print_r($result_location);
//        die("qwerty");
        $collection = $this->di->getObjectManager()->get('App\Shopifygql\Models\Collections');
        /*$shopData = $collection::findFirst(
            ['conditions' => 'user_id=' . $user_id]);*/
        $connection = $collection->getDbConnection();
        $connection->query("DELETE FROM `shopify_collections` WHERE `shopify_collections`.`user_id` =".$user_id);
        if ($result) {
//            print_r($result);
//            die("daed001");
            if (count($result['custom_collections'])) {
                foreach ($result['custom_collections'] as $value){
//                    print_r($value['title']);
//                    die("daed0001");
//                    print_r($value);
//                    die("daed0001");
                    /*$data = [
                        'user_id' => $user_id,
                        'shop_id' => $shop_id,
                        'collection_id' => $value['admin_graphql_api_id'],
                        'title' => $value['title']
                    ];
                    print_r($data);
                    $collection->set($data);
                    $status = $collection->save();
                    echo PHP_EOL;
                    print_r($status);*/
//                    echo "INSERT INTO `shopify_collections` (`user_id`, `collection_id`, `title`, `shop_id`) VALUES (".$user_id.",'".$value['admin_graphql_api_id']."','".$value['title']."',".$shop_id.")" . PHP_EOL;
//                    die("daed0001");
                    $connection->query("INSERT INTO `shopify_collections` (`user_id`, `collection_id`, `title`, `shop_id`) VALUES (".$user_id.",'".$value['admin_graphql_api_id']."',". '"'.$value['title']. '"' . ",".$shop_id.")");
                }

                echo PHP_EOL;
                print_r("smart waa");
                echo PHP_EOL;
//                die("STOP");
                foreach ($result['smart_collections'] as $items){
                    $data = [
                        'user_id' => $user_id,
                        'shop_id' => $shop_id,
                        'collection_id' => $items['admin_graphql_api_id'],
                        'title' => $items['title'],
                        'rules' => [],
                    ];
                    if (count($items['rules'])>0){
                        $data['rules'] = json_encode($items['rules'],true);
                    }

//                    print_r($data);
                    /*$collection->set($data);

                    $status = $collection->save();
                    print_r($status);*/
                    echo PHP_EOL;
                    echo PHP_EOL;
                    print_r("INSERT INTO `shopify_collections` (`id`, `user_id`, `collection_id`, `title`, `rules`, `shop_id`) VALUES (NULL,"."'".$user_id."','".$items['admin_graphql_api_id']."','".$items['title']."','".$data['rules']."','".$shop_id."')");
//                    die("daed00000000002");
                    $connection->query("INSERT INTO `shopify_collections` (`id`, `user_id`, `collection_id`, `title`, `rules`, `shop_id`) VALUES (NULL,"."'".$user_id."','".$items['admin_graphql_api_id']."','".$items['title']."','".$data['rules']."','".$shop_id."')");
                    echo PHP_EOL;
                }
            }

            return $this->prepareResponse(['success'=>true,'code'=>'Collection Synced']);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'Collection not Synced']);
    }
}
?>
