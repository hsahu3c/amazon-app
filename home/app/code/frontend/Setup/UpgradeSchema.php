<?php

namespace App\Frontend\Setup;

use App\Core\Setup\Schema;
use \Phalcon\Di\DiInterface;

class UpgradeSchema extends Schema
{
    public function up($di, $moduleName, $currentVersion): void
    {
        $this->applyOnSingle($di, $moduleName, $currentVersion, 'db', function ($connection, $dbVersion) use ($di): void {
            if ($dbVersion < '1.0.0') {
//                $connection->query("select * from user;");


            }
        });

    }
}