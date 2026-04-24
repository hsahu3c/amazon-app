<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;

class AppReInstallEvent extends \App\Core\Components\Base
{

    /**
     * @param Event $event
     * @param $myComponent
     */
    public function appReInstall(Event $event, $myComponent, $data)
    {

        $this->di->getLog()->logContent("data" . json_encode($data), 'info', 'AshishTestStaging.log');
        $userId = $this->di->getUser()->id;
        $userName = $this->di->getUser()->username;
        $email = $this->di->getUser()->email;
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $mongo = $this->di->getObjectManager()->get("App\Core\Models\BaseMongo");
        $userDetailCollection = $mongo->getCollection("user_details");
        $shop  = $data['source_shop'];
        $appCode = $data['re_install_app'];
        $isBackFound = $userDetails->isBackupPresent($userName, $email, $appCode);

        $unsetOperations = [
            'shops.$[shop].apps.$[app].erase_data_after_date' => true,
            'shops.$[shop].apps.$[app].uninstall_date' => true,
            'shops.$[shop].apps.$[app].temporarly_uninstall' => true,
        ];

        $updateDocument = ['$unset' => $unsetOperations];

        if ($isBackFound['success']) {
            $updateDocument['$set'] = [
                'shops.$[shop].apps.$[app].reinstalled' => true,
                'shops.$[shop].apps.$[app].reinstalled_at' => new \MongoDB\BSON\UTCDateTime()
            ];
        }

        $userDetailCollection->updateOne(
            ["user_id" => $userId],
            $updateDocument,
            [
                'arrayFilters' => [
                    [
                        'shop._id' => $shop['_id']
                    ],
                    [
                        'app.code' => $appCode
                    ]
                ]
            ]
        );
        $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->setStatusInUser("active", false, $shop['_id'], $appCode);
        $keys = ['sources', 'targets'];
        foreach ($keys as $key) {
            $oppositeKey = $key === 'sources' ? 'targets' : 'sources';
            if (isset($shop[$oppositeKey])) {
                foreach ($shop[$oppositeKey] as $item) {
                    $updateResponse = $userDetailCollection->updateOne(
                    ['user_id' => $userId],
                    [
                        '$unset' => [
                            "shops.$[shop].$oppositeKey.$[sourceTarget].disconnected" => true,
                            "shops.$[shop].$oppositeKey.$[sourceTarget].erase_data_after_date" => true,
                            "shops.$[shop].$oppositeKey.$[sourceTarget].uninstall_date" => true,
                            "shops.$[shop].$oppositeKey.$[sourceTarget].uninstall_app_code" => true,
                            "shops.$[shop].$oppositeKey.$[sourceTarget].app_tag" => true,
                            "shops.$[shop].deletion_in_progress" => true,
                        ],
                    ],
                    [
                        'arrayFilters' => [
                            ["shop._id" => $shop['_id']],
                            ["sourceTarget.shop_id" => $item['shop_id'], "sourceTarget.uninstall_app_code" => $appCode],
                        ],
                    ]);

                    if ($key === 'sources') {
                        $userDetailCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    "shops.$[shop].sources.$[source].disconnected" => true,
                                ],
                            ],
                            [
                                'arrayFilters' => [
                                    ["shop._id" => $item['shop_id']],
                                    ["source.shop_id" => $shop['_id'], "source.app_code" => $appCode],
                                ],
                            ]
                        );
                    }
                }
            }
        }
    }
}