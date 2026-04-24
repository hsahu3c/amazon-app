<?php
/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 22/8/22
 * Time: 4:10 PM
 */

namespace App\Connector\Models\Shipment;

class Helper
{
    public function fetchCarriers(array $data): array
    {
        $target = '';
        if(isset($data['target']))
        {
            $target = $data['target'];
        }
        else{
            $target = $this->di->getRequester()->getTargetName();
        }

        if($target != '')
        {
            try {
                $moduleHome = ucfirst($target);
                $class = '\App\\' . $moduleHome . '\Models\SourceModel';
                $this->di->getObjectManager()->get($class);
                $target = $moduleHome;

            } catch (\Exception) {
                $moduleHome = ucfirst($target)."home";
                $class = '\App\\' . $moduleHome . '\Models\SourceModel';
                $this->di->getObjectManager()->get($class);
                $target = $moduleHome;
            }

            $filePath = CODE.DS.strtolower($target).DS.'utility'.DS.'shipping_carriers.json';
            if(file_exists($filePath))
            {
                $orderDatas = json_decode(file_get_contents($filePath),true);
            }
            else{
                return ['status' => false, 'message' => 'file path - '.$filePath.' does not exist'];
            }

            return ['status' => true, 'message' => $orderDatas];
        }
        return ['status' => false, 'message' => 'target cannot be blank, it is required'];
    }
}
