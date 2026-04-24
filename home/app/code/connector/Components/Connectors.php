<?php

namespace App\Connector\Components;

use function MongoDB\is_string_array;

class Connectors extends \App\Core\Components\Base
{

    /**
     * @param bool $userId
     * @param string $search
     * @return array
     */
    public function getAllConnectors($userId = false, $search = '')
    {
        if ($userId) {
            $connectors = [];
            foreach ($this->di->getConfig()->connectors->toArray() as $code => $connector) {
                if ($search == '') {
                    $connectors[$code] = $connector;
                    $connectors[$code]['installed'] = $this->checkIsInstalled($code, $userId);
                } else {
                    if (strpos($connector['title'], $search) !== false ||
                        strpos($connector['code'], $search) !== false) {
                        $connectors[$code] = $connector;
                        $connectors[$code]['installed'] = $this->checkIsInstalled($code, $userId);
                    }
                }
            }

            return $connectors;
        }

        return $this->di->getConfig()->connectors->toArray();
    }

    /**
     * @param $code
     * @return mixed
     */
    public function getConnectorModelByCode($code)
    { 
        if($this->di->getConfig()->connectors->get($code)){

            return $this->di->getObjectManager()->get($this->di->getConfig()->connectors->$code->source_model);
        }

        return false;
    }

    public function getShops($code,$userId,$getDetails = false){
        if($this->getConnectorModelByCode($code))
            return $this->getConnectorModelByCode($code)->getShops($userId,$getDetails);

        return [];
    }

    /**
     * @param $code
     * @return mixed
     */
    public function getConnectorByCode($code)
    {
        return $this->di->getConfig()->connectors->get($code);
    }

    /**
     * @param array $array
     * @param $filters
     * @return array
     */
    public function filterConnectorCollection($array, $filters)
    {
        return array_filter($array, function (array $data) use ($filters): bool {

            foreach ($filters as $key => $filter) {

                if (!is_array($filter)) {
                    if ($filter != $data[$key]) {
                        return false;
                    }
                } elseif (is_array($filter)) {
                    if (!isset($data[$key]) || !$this->checkConditionalValue($data[$key], $filter)) {
                        return false;
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
    public function getConnectorsWithFilter($filters = [], $userId = false)
    {
        return $this->filterConnectorCollection($this->getAllConnectors($userId), $filters);
    }

    /**
     * @param $framework
     * @param $userId
     * @return int
     */
    public function checkIsInstalled($framework, $userId)
    {
        $installed = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $details = $collection->findOne(['user_id' => (string)$userId ]);
        $eventsManager = $this->di->getEventsManager();
        if ( isset($details['shops']) ) {
            foreach( $details['shops'] as $value ) {
                if ( $value['marketplace'] === $framework ) {
                    $reference = &$value;
                    $eventsManager->fire('application:beforeConnectorGetAll', $this, $reference);
                    $installed[] = $value;
                }
            }
        }

        return $installed;
    }
}
