<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
class Helper_DbConn extends Base
{

      public function Createuser($username,$password)
      {

      	$response=$this->di->getObjectManager()->get('\App\Core\Models\Ptest')->register_user($username,$password);
      	 return $response;
      }

        public function Edituser($id,$username,$password)
      {
         $response=$this->di->getObjectManager()->get('\App\Core\Models\Ptest')->edit_user($id,$username,$password);
         return $response;

      }

      public function Getuser($id)
      {

      	$response=$this->di->getObjectManager()->get('\App\Core\Models\Ptest')->get_user($id);
      	return $response;
      }

       public function Deleteuser($id)
      {

         $response=$this->di->getObjectManager()->get('\App\Core\Models\Ptest')->delete_user($id);
         return $response;
      }
}
