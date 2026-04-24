<?php

namespace App\Connector\Components;


class Data extends \App\Core\Components\Base
{
    public function sqlRecords($query, $type = null, $queryType = null)
    {
        $baseModel = $this->di->getObjectManager()->get('App\Core\Models\Base');
        $connection = $baseModel->getDbConnection();
        try {
            // Start a nested transaction
            $connection->begin();
            if ($queryType == "update" || $queryType == "delete" || $queryType == "insert" || ($queryType == null && $type == null)) {
                $response = $connection->query($query);
            } elseif ($type == 'one') {
                $response = $connection->fetchOne($query);
            } else {
                $response = $connection->fetchAll($query);
            }

            return $response;
        } catch (\Exception) {
            // An error has occurred, release the nested transaction
            $connection->rollback();
        }
    }
}
