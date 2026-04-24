<?php

namespace App\Core\Controllers;

use App\Core\Models\User;

class AuthorisationController extends BaseController
{
    public function authorizeAction()
    {
        $requestData = $this->getRequestData();
        $filePath = BP . DS . 'var' . DS . 'temp.php';
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($requestData, true) . ';');
        fclose($handle);
        return true;
    }
}
