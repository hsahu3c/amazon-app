<?php

namespace App\Core\Components;

use \App\Core\Components\Base;

/**
 * updates status of the given service of
 * the status app
 */
class StatusApp extends Base
{
    protected $statusDb;

    public function _construct()
    {
        $this->statusDb = $this->di->getDb()->status_app;
    }
    /**
     * evaluates and notifies
     * via api and email
     *
     * @param string marketPlace
     * @param string service
     * @param string reason
     * @return void
     */
    public function inform($marketPlace, $service, $reason)
    {
        $statusCountThresh = $this->di->getConfig()->path("status_app_update.status_count", 10);
        $apiUpdateInterval = $this->di->getConfig()->path("status_app_update.api_update_interval", 100);
        $maxStatusStale = $this->di->getConfig()->path("status_app_update.data_stale", 50);
        $data = $this->statusDb->findOne(
            [
                'marketplace' => $marketPlace,
                'service' => $service
            ]
        );
        if ($data) {
            $dbTime = $data->at->toDateTime()->getTimestamp();
            $currentTime = time();
            if ($data->status_count < $statusCountThresh) {
                // if data is stale , reset
                if ($currentTime - $dbTime > $maxStatusStale) {
                    $this->statusDb->updateOne(
                        [
                            'marketplace' => $marketPlace,
                            'service' => $service,
                        ],
                        [
                            '$set' => [
                                'status_count' => 1
                            ],
                            '$currentDate' => [
                                'at' => true
                            ]
                        ],
                        ["upsert" => true]
                    );
                    return;
                }
                $this->statusDb->updateOne(
                    [
                        'marketplace' => $marketPlace,
                        'service' => $service,
                    ],
                    [
                        '$inc' => [
                            'status_count' => 1,
                        ],
                        '$currentDate' => [
                            'at' => true
                        ]
                    ],
                    ["upsert" => true]
                );
            } else {
                // update status api , and reset
                $lastApiUpdate = $data->last_api_update->toDateTime()->getTimestamp();
                $update = [
                    'status_count' => 1,
                ];
                // notify the interested ones
                if ((time() - $lastApiUpdate) > $apiUpdateInterval) {
                    $this->notify($marketPlace, $service, $reason);
                    $update['last_api_update'] = new \MongoDB\BSON\UTCDateTime();
                }
                $this->statusDb->updateOne(
                    [
                        'marketplace' => $marketPlace,
                        'service' => $service,
                    ],
                    [
                        '$set' => $update,
                        '$currentDate' => [
                            'at' => true
                        ]
                    ]
                );
            }
        } else {
            $this->statusDb->insertOne(
                [
                    'marketplace' => $marketPlace,
                    'service' => $service,
                    'status_count' => 1,
                    'last_api_update' =>  new \MongoDB\BSON\UTCDateTime(),
                    'at' => new \MongoDB\BSON\UTCDateTime()
                ]
            );
        }
    }
    protected function notify($marketPlace, $service, $reason)
    {
        $this->sendEmail(...func_get_args());
    }
    protected function sendEmail($marketPlace, $service, $reason)
    {
        $this->di->getObjectManager()->get('\App\Core\Components\SendMail')->send(
            [
                'email' => $this->di->getConfig()->path('mailer.sender_email', null),
                'subject' => "issues detected : $marketPlace $service",
                'path' => 'core' . DS . 'view' . DS . 'email' . DS . 'statusIssues.volt',
                'marketplace' => $marketPlace,
                'service' => $service,
                'reason' => $reason,
                'at' => date("Y-m-d h:i:sa")
            ]
        );
    }
    protected function updateApi($marketPlace, $service, $reason)
    {
        $modules = $this->di->getObjectManager()
            ->get("App\Core\Components\Setup")
            ->statusAction();
        if (!($modules['statusapp'] ?? null) == 1) {
            return;
        }
        $statusAppComponent = $this->di->getObjectManager()->get('\App\Statusapp\Components\Request');
        $host = $statusAppComponent->prepareUrl([
            'app' => $marketPlace,
            'module' => $service
        ]);
        $statusAppComponent->awsSignature(
            "execute-api",
            $host,
            $this->di->getConfig()->path('status_app.region', 'ap-south-1'),
            "PATCH",
            [
                'app' => $marketPlace,
                'module' => $service,
                'reason' => $reason,
                'status' => 'active'
            ]
        );
        $reason = json_decode($statusAppComponent->send()->getBody()->getContents(), true);
        // if service not found , update global
        if (!$reason['success']) {
            $statusAppComponent->awsSignature(
                "execute-api",
                $host,
                $this->di->getConfig()->path('status_app.region', 'ap-south-1'),
                "PATCH",
                [
                    'app' => $marketPlace,
                    'module' => 'global',
                    'reason' => $reason,
                    'status' => 'active'
                ]
            );
        }
    }
}
