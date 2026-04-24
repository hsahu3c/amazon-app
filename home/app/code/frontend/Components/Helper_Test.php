<?php
namespace App\Frontend\Components;
use App\Core\Components\Base;
use Phalcon\Crypt;
class Helper_Test extends Base
{
    public function login($value) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("testpriyanshu");

        $collection = $mongo->getCollection();
        $check = $collection->find(['username'=>$value['username']],["typeMap" => ['root' => 'array', 'document' => 'array'],'limit'=>4])->toArray();
//       $out = $collection->find([],['fname'=> 'Priyanshumishra']);
        if($check==[]){
            return["Data"=>'no data found in db',
                'status'=>false
            ];
        }
        $key = "1";
        $crypt = new Crypt('bf-cbc', true);
        $de=mb_convert_encoding($check[0]['password'], 'ISO-8859-1');
        $decrypted=$crypt->decrypt($de, $key);
        return["Data"=>'hello user -> '.$check[0]['username'],
            'status'=>true,
            'password'=>($check[0]['password']==$decrypted),
            'encrypted_password'=>$check[0]['password'],
            'decrypted_password'=>$decrypted
            ,
        ];

    }

    public function registeration($data){
        $mongo= $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->setSource('testpriyanshu');
        $collection=$mongo->getCollection();
        $getAlldatamongo = $collection->findOne(['username'=>$data['username']]);
        if($getAlldatamongo==null){
            $key = "1";
            $crypt = new Crypt('bf-cbc', true);
            $text = $data['password'];
            $encrypted = $crypt->encrypt($text, $key);
            $en=mb_convert_encoding($encrypted, 'UTF-8', 'ISO-8859-1');
            $out = $collection->insertOne(['username'=>$data['username'],'email'=>$data['email'],'password'=>$en,'timestamp'=>time()]);
            return["status"=>'new user created'];
        }
        $set=$collection->updateOne(
            ['username'=>$getAlldatamongo['username']],
            ['$set'=>['username'=>$data['username'],'email'=>$data['email'],'password'=>$data['password'],'timestamp'=>time()]]);
        return['data'=>$set,"status"=>'username already registered hence updated'];
    }

    public function TestRun($user,$pass,$auth)
    {
        # code...
        $this->di->getObjectManager()->get('\App\Core\Models\Test')->set($user,$pass,$auth);
        return ['success' => true, 'code' => 'Code Is Running', 'message' => 'Code Is Sunning :P'];
    }

    public function TestRunGet($value)
    {
        # code...
        $data = $this->di->getObjectManager()->get('\App\Core\Models\Test')->get($value);
        return ['success'=>true,"data" => $data ];
    }

    public function FunctionName($value): void
    {
        # code...

    }

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
}