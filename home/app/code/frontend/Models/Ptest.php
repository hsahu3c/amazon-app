<?php
namespace App\Frontend\Models;

use App\Core\Models\Base;
class Ptest extends Base
{
    protected $table = 'testp';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
        //$this->setReadConnectionService('dbSlave');
        //$this->setWriteConnectionService('dbMaster');
    }

    public function get_data($data){

        $configg=Ptest::findFirst([
            "username = '{$data["username"]}'AND password = '{$data["password"]}'"
        ]);
        return ["data" => $configg];
    }

    public function insert_data($data){

        $config=Ptest::findFirst([
            "username = '{$data["username"]}'OR email = '{$data["email"]}'"
        ]);
        $total = Ptest::find();

        if(!$config){
            $res = Ptest::save([
                'uid'=>$config['uid'],
                'username' => $data["username"],
                'password' => $data["password"],
                'email' => $data["email"],
            ]);
            return ["data" => [
                "success"=>$res,
                "check"=>$config,
                "updated"=>false,
                "Total_no"=>count($total),
                "Already_registered"=>false]
            ];
        }
        $config->username = $data["username"];
        $config->password = $data["password"];
        $config->email = $data["email"];
        $config-> save();
        return ["data" =>[
                    "success"=>false,
                    "updated"=>true,
                    "check"=>$config,
                    "Already_registered"=>true,
                     "Total_no"=>count($total)]
            ];
    }

    public function register_user($username,$password)
    {
    	$response=[];
    	if (isset($username) && isset($password)) {
    		$config=Ptest::findFirst("username='{$username}' AND password='{$password}'");
            if ($config) {
              $response=$this->prepareResponse(['success'=>'true', 'code'=>'User  Exists ', 'message' => 'User Exists']);
              return $response;
            }
            $this->setUsername($username)->setPassword($password)->save();
    }
}

    public function edit_user($id,$username,$password): void
    {
    	$response=[];
    	if (isset($username) && isset($password)) {

    		$configg=Ptest::findFirst("uid='{$id}'");
            if ($configg) {

               $configg->setUsername($username)->setPassword($password)->save();

            }
            else
            {

            }
    }
}

public function get_user($id)
{
	$response=[];
	$configg=Ptest::findFirst("uid={$id}");
   $response=['id'=>$configg->getUid(),'username'=>$configg->getUsername(), 'password' => $configg->getPassword()];
   return $response;


}

public function delete_user($id): void
{
    $response=[];
    $configg=Ptest::findFirst("uid={$id}");
    var_dump($configg);
    // $connection = $this->di->get($configg);
    //   die('came-heeerre');
    // $connection->query("DELETE From testp WHERE uid = " . $id);
   // $response=['message'=>'Deleted User Successfully'];
   // return $response;
}

}
?>
