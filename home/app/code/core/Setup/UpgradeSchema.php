<?php

namespace App\Core\Setup;

use App\Core\Models\User;
use \Phalcon\Di\DiInterface;

class UpgradeSchema extends Schema
{
    public function up(DiInterface $di, $moduleName, $currentVersion)
    {
        $this->applyOnSingle($di, $moduleName, $currentVersion, 'db', function ($connection, $dbVersion) use ($di) {
            if ($dbVersion < '1.0.1') {
                $collection = $connection->selectCollection('acl_role');
                $collection->insertMany([
                    [
                        "code" => "admin",
                        "title" => "Administrator",
                        "description" => "ACL group allotted to administrators",
                        "resources" => "all"
                    ],
                    [
                        "code" => "app",
                        "title" => "app",
                        "description" => "ACL group allotted to app",
                        "resources" => ""
                    ],
                    [
                        "code" => "customer",
                        "title" => "customer",
                        "description" => "customer",
                        "resources" => ""
                    ],
                    [
                        "code" => "anonymous",
                        "title" => "anonymous",
                        "description" => "anonymous",
                        "resources" => "anonymous"
                    ],
                    [
                        "code" => "refresh",
                        "title" => "refresh",
                        "description" => "refresh",
                        "resources" => "refresh"
                    ]

                ]);
                $collection->createIndex(['code' => 1], ["unique" => true]);

                $user = $di->getObjectManager()->create('\App\Core\Models\User');
                $user->createUser([
                    'username' => 'app',
                    'email' => 'ankurverma@cedcoss.com',
                    'password' => 'Password@123'
                ], 'app');
                $collection = $connection->selectCollection('user_details');
                $collection->updateOne(
                    ["username" => "app"],
                    ['$set' => ["confirmation" => 1, "status" => 2]]
                );
                $data = $user->login([
                    'username' => 'app',
                    'email' => 'ankurverma@cedcoss.com',
                    'password' => 'Password@123'
                ]);
                if ($data['success']) {
                    echo 'App Token : ' . PHP_EOL . $data['data']['token'] . PHP_EOL . PHP_EOL;
                } else {
                    echo PHP_EOL . $data['message'] . PHP_EOL . PHP_EOL;
                }

                $user = $di->getObjectManager()->create('\App\Core\Models\User');
                $user->createUser([
                    'username' => 'admin',
                    'email' => 'satyaprakash@cedcoss.com',
                    'password' => 'Password@123'
                ], 'admin');
                $collection->updateOne(
                    ["username" => "admin"],
                    ['$set' => ["confirmation" => 1, "status" => 2]]
                );
                $data = $user->login([
                    'username' => 'admin',
                    'email' => 'satyaprakash@cedcoss.com',
                    'password' => 'Password@123'
                ]);
                if ($data['success']) {
                    echo 'Admin User : admin'
                        . PHP_EOL . 'Password: Password@123'
                        . PHP_EOL . 'Admin User Token : '
                        . PHP_EOL . $data['data']['token']
                        . PHP_EOL . PHP_EOL;
                } else {
                    echo PHP_EOL . $data['message'] . PHP_EOL . PHP_EOL;
                }
            }
            if ($dbVersion < '1.0.3') {
                $users = $di->getObjectManager()->create('\App\Core\Models\User');
                foreach ($users->find() as $user) {
                    $user->password = $users->getHash("P@@sw@@rd@289");
                    $user->save();
                }
            }
            if ($dbVersion < '1.0.4') {
                $user = $di->getObjectManager()->create('\App\Core\Models\User');
                $user->createUser([
                    'username' => 'anonymous',
                    'email' => 'anonymous@cedcommerce.com',
                    'password' => 'P@@sw@@rd@289'
                ], 'anonymous');
            }
            if ($dbVersion < '1.0.5') {
                // Creating ttl index to remove expired tokens
                $collection = $connection->selectCollection('token_manager');
                $collection->createIndex(["expd" => 1], ["expireAfterSeconds" => 0]);
            }
            if ($dbVersion < '1.0.6') {
                foreach (User::find() as $user) {
                    if (!($user->last_used_passwords ?? null)) {
                        $user->last_used_passwords = [$user->password];
                        $user->save();
                    }
                }
            }

            if ($dbVersion < '1.0.7') {
                // Creating ttl index to remove expired tokens
                $collection = $connection->selectCollection('user_generated_tokens');
                $collection->createIndex(["expires_at" => 1], ["expireAfterSeconds" => 0]);
            }
            return true;
        });
    }
}
