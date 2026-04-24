<?php
namespace App\Frontend\Components;
use App\Core\Components\Base;
class Importer_Helper extends Base
{
    public function newsFromData($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("omni_news_data");

        $collection = $mongo->getCollection();
        $new_data = [
            "_id"=>uniqid(),
            "title" => $data['data'][0]["title"],
            "description" => $data['data'][1]["description"],
            "image_url" =>$data['data'][2]['imageurl'],
            "content_link" => $data['data'][3]['content_link']
        ];
        if ($new_data['title'] != null||$new_data['description'] != null||$new_data['image_url'] != null||$new_data['content_link'] != null) {
            $collection->insertOne($new_data);
        }

        $fetch_data = $collection->find()->toArray();
        return $fetch_data;
    }

    public function deleteData($object_id){

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("omni_news_data");

        $collection = $mongo->getCollection();
        $collection->deleteOne(['_id' =>$object_id['data']]);
        return true;
    }

    public function addBlogs($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("omni_blogs_data");

        $collection = $mongo->getCollection();
        $new_data = [
            "_id"=>uniqid(),
            "title" => $data['data'][0]["title"],
            "description" => $data['data'][1]["description"],
            "image_url" =>$data['data'][2]['imageurl'],
            "content_link" => $data['data'][3]['content_link']
        ];
        if ($new_data['title'] != null||$new_data['description'] != null||$new_data['image_url'] != null||$new_data['content_link'] != null) {
            $collection->insertOne($new_data);
        }

        $fetch_data = $collection->find()->toArray();
        return $fetch_data;
    }

    public function deleteBlogData($object_id){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("omni_blogs_data");

        $collection = $mongo->getCollection();
        $collection->deleteOne(['_id' =>$object_id['data']]);
        return true;
    }

    public function getCredits($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb("db_importer");
        $mongo->setSource("user_service");

        $collection = $mongo->getCollection();
        $res = $collection->findOne(['code' => 'product_import','merchant_id' => (string)$data['user_id']]);
        return $res;
    }

    public function getUpdateCredits($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//        $mongo->initializeDb("db_importer");
        $mongo->setSource("user_service");

        $collection = $mongo->getCollection();
        if ( $collection->count(['code' => 'product_import','merchant_id' => (string)$data['user_id']]) ) {
            $res = $collection->updateOne(['code' => 'product_import','merchant_id' => (string)$data['user_id']],
                ['$set' =>['available_credits'=>(int)$data['available_credit_update']]]);
        } else {
            $res = $collection->insertOne([
                'code' => 'product_import',
                'merchant_id' => (string)$data['user_id'],
                'available_credits'=>(int)$data['available_credit_update'],
                'total_used_credits' => 0,
                'type' => 'importer',
                'charge_type' => 'Prepaid'
            ]);
        }

        return $res;
    }

    public function reviewRating($data){
        $user_id = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("rating_data_".$user_id);

        $collection = $mongo->getCollection();
        $new_data = [
            "thumb_action" => $data['data'][0]["thumb_action"],
            "is_Done_Rating" => $data['data'][1]["submit_review"],
            "query_message" =>$data['data'][2]['textbox_query'],
        ];
        $collection->insertOne($new_data);
        return true;
    }

    public function fetchReviewRating($user_id){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("rating_data_".$user_id);

        $collection = $mongo->getCollection();
        $data_rating = $collection->find()->toArray();
        return $data_rating;
    }

    public function neverAskAgainReviewSystem($data){
        $user_id = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("rating_data_".$user_id);

        $collection = $mongo->getCollection();
        $new_data = [
            "thumb_action" => 'sorry to say user clicked never ask again',
            "is_Done_Rating" => $data['data'][0]["submit_review"],
            "query_message" =>$data['data'][1]['textbox_query'],
        ];
            $collection->insertOne($new_data);
            return true;
    }
}