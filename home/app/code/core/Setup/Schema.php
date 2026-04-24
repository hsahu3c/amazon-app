<?php

namespace App\Core\Setup;

use \Phalcon\Di\DiInterface;

class Schema extends \App\Core\Components\Base
{
    public function upgrade(DiInterface $di, $moduleName, $currentVersion)
    {
        try {
            $this->up($di, $moduleName, $currentVersion);
        } catch (\Exception $exception) {
            echo $exception->getMessage();
            PHP_EOL;
            die;
        }
    }

    public function applyOnAll($di, $moduleName, $currentVersion, callable $callback)
    {
        foreach ($di->get('config')->databases as $key => $value) {
            if ($value->adapter != 'Mongo') {
                $this->applyOnSingle($di, $moduleName, $currentVersion, $key, $callback);
            }
        }
    }

    public function applyOnSingle($di, $moduleName, $currentVersion, $dbname = 'db', callable $callback = null)
    {

        $connection = $di->get("db");


        if ($di->getConfig()->databases->db->adapter == 'Mongo') {

            $collection = $connection->selectCollection('setup_module');

            $dbVersion = $collection->findOne(
                [
                    "module" => $moduleName
                ],
                [
                    "typeMap" =>
                    [
                        'root' => 'array',
                        'document' => 'array'
                    ]
                ]
            );



            try {
                $dbConnection = $di->get($dbname);
                if ($dbVersion && isset($dbVersion['version'])) {
                    if ($currentVersion > $dbVersion['version']) {

                        $result = call_user_func($callback, $dbConnection, $dbVersion['version']);
                        if ($result) {

                            $collection->updateOne(
                                ["module" => $moduleName],
                                ['$set' => ["version" => $currentVersion]]
                            );
                        }
                    }
                } else {

                    $result = call_user_func($callback, $dbConnection, '');

                    if ($result) {
                        $collection->insertOne(["module" => $moduleName, "version" => $currentVersion]);
                    }
                }
            } catch (\Exception $exception) {


                echo $exception->getMessage();
                PHP_EOL;
                die;
            }
        } else {
            $connection->begin();

            try {
                $dbVersion = $connection
                    ->query("Select version From setup_module where module = '{$moduleName}'")
                    ->fetch();
            } catch (\Exception $e) {

                if ($e->getCode() == '42S02') {
                    $connection->query("CREATE TABLE `setup_module` (`id` int(3) NOT NULL AUTO_INCREMENT, `module` varchar(30) NOT NULL, `version` varchar(20) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
                    $dbVersion = [];
                }
            }
            try {
                $dbConnection = $di->get($dbname);
                if (isset($dbVersion['version']) && ($currentVersion > $dbVersion['version'])) {
                    $result = call_user_func($callback, $dbConnection, $dbVersion['version']);
                } else {
                    $result = call_user_func($callback, $dbConnection, '');
                }
                $connection->commit();
            } catch (\Exception $exception) {
                $connection->rollback();
                echo $exception->getMessage() . PHP_EOL;
                die;
            }
        }
    }
}
