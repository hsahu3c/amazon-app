<?php

namespace App\Connector\Models\Mongostream;

use App\Core\Models\Base;


class Core extends Base
{
    public function receiveSqsMessage($data)
    {
        $data = $data['data'];
        if (isset($data['className']) && isset($data['method'])) {
            return  $this->route($data['className'], $data['method'], $data);
        }

        print_r($data);
        echo "className or method not provided";
        return true;
    }

    public function route($path, $method, $data)
    {
        if ((method_exists($this->di->getObjectManager()->get($path), $method))) {
            return $this->di->getObjectManager()->get($path)->$method($data);
        }

        echo "Class or Method doest not exists!!!";
        return true;
    }
}
