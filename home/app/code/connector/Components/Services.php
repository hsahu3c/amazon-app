<?php

namespace App\Connector\Components;

class Services extends \App\Core\Components\Base
{

    /**
     * @param bool $userId
     * @param string $search
     * @return array
     */
    public function getAll($userId = false,$includeModel = false)
    {
        $services = [];
        if(isset($this->di->getConfig()->services)){
            if ($userId) {
                foreach ($this->di->getConfig()->services->toArray() as $code => $service) {

                    $model = $this->getServiceModel($service, $userId);

                    if ($includeModel) {
                        $service['model'] = $model;
                    }

                    $service['usable'] = $model->canUseService();
                    $service['usable'] = true;
                    $service['shops'] = $this->getShops($service['marketplace'],$userId,true);
                    $services[$code] = $service;
                }

            }
            else{
                foreach ($this->di->getConfig()->services->toArray() as $code => $service) {

                    $services[$code] = $service;
                }
            }
        }

        return $services;
    }

    /**
     * @param $service
     * @param $userId
     * @return mixed
     */
    public function getServiceModel($service,$userId){

        $userService = $this->di
            ->getObjectManager()
            ->create($service['handler']);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $data = $userService->getCollection()->findOne(['merchant_id' => (string)$userId,'code' => $service['code']], $options);
        if ($data) {
            $userService->setData($data);
        }

        return $userService;
    }

    /**
     * @param $code
     * @return mixed
     */
    public function getModel($code)
    {
        return $this->di->getObjectManager()->get($this->di->getConfig()->services->$code->handler);
    }

    public function getShops($marketplace,$userId,$getDetails){
        return $this->di->getObjectManager()->get('\App\Connector\Components\Connectors')->getShops($marketplace,$userId,$getDetails);
    }

    /**
     * @param $code
     * @return mixed
     */
    public function getByCode($code)
    {
        return $this->di->getConfig()->services->$code;
    }

    /**
     * @param array $array
     * @param $filters
     * @return array
     */
    public function filterCollection($array, $filters = [])
    {
        return array_filter($array, function (array $data) use ($filters): bool {
            if (is_array($filters)) {
                foreach ($filters as $key => $filter) {
                    if (is_string($filter)) {
                        if ($filter != $data[$key]) {
                            return false;
                        }
                    } elseif (is_array($filter)) {
                        if (!isset($data[$key]) || !$this->checkConditionalValue($data[$key], $filter)) {
                            return false;
                        }
                    }
                }
            }

            return true;
        });

    }

    /**
     * @param $value
     * @param $filter
     * @return bool
     */
    public function checkConditionalValue($value, $filter)
    {
        switch ($filter['condition']) {
            case 'neq':
                if ($filter['value'] != $value) {
                    return true;
                }

                break;
            default:
                return false;
        }
    }

    /**
     * @param array $filters = ['key'=>'value','key2'=>'value','key3'=>['condition'=>'neq','value'='test']]
     * @param bool $userId
     * @return array
     */
    public function getWithFilter($filters = [],$userId = false)
    {
        $services = $this->filterCollection($this->getAll($userId), $filters);
        return $services;
    }

    /**
     * @param $type
     * @param $userId
     */

    public function getUsableServices($type, $userId): void
    {
        $this->filterCollection($this->getAll(), ['type'=>$type]);
    }

    /**
     * @param $framework
     * @param $userId
     * @return int
     */
    public function checkIsInstalled($framework, $userId)
    {
        $config = \App\Connector\Models\User\Connector::findFirst("code='{$framework}' AND user_id='{$userId}'");
        if ($config) {
            return 1;
        }

        return 0;
    }



}
