<?php

namespace App\Amazon\Components;

use App\Core\Components\Base as Base;
use App\Connector\Components\ApiClient;

class ReverseAuth extends Base
{
    public function CheckForInstallation($data)
    {
        if (!isset($data['shop'])) {
            return ['success' => false, "message" => "shop_id is missing"];
        }

        $apiConnector = $this->di->getConfig()->get('apiconnector');
        $sAppId = $apiConnector
            ->get('amazon')
            ->get('amazon')->get('sub_app_id');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("fromAmazonInstallation");
        $shop = $collection->findOne(['shop_url' => $data['shop']]);
        if (!empty($shop)) {
            $response = $this->di->getObjectManager()->get(ApiClient::class)
                ->init('amazon', true, 'amazon')
                ->call('update-sAppId', [], ['remote_shop_id' => $shop['shop_id'], 'sAppId' => $sAppId], 'POST');
            if (isset($response['success']) && $response['success']) {
                $dataParam = [
                    'shop' => $shop['shop_id'],
                    'marketplace' => $shop['marketplace'],
                ];
                $dataParam = [
                    "success" => true,
                    "data" => [
                        "data" => [
                            "shop_id" => $shop['shop_id'],
                            "remote_shop_ids" => [
                                $shop['shop_id']
                            ],
                            "marketplace" => $shop['marketplace']
                        ],
                        //   "exp" => 1693300618,
                        "iss" => "https://apps.cedcommerce.com",
                    ],
                    "rawResponse" => $shop['fullRemoteData']

                ];
                $dataParam['rawResponse']['state'] = json_encode([
                    "app_tag" => "amazon_sales_channel",
                    "user_id" => $this->di->getUser()->id,
                    "source" => $data['details']['marketplace'],
                    "source_shop_id" => $data['details']['_id']

                ]);
                $dataParam['rawResponse']['app_code'] = 'amazon';
                $dataParam['direct_call'] = true;
                $response = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel')->commenceHomeAuth($dataParam);
                if ($response['success']) {
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire('application:afterreverceAuth', $this);
                    unset($response['access_token']);
                    unset($response['redirect_to_dashboard']);
                    unset($response['refresh_token']);
                    unset($response['shop']);
                    unset($response['heading']);
                    $collection->deleteOne(['shop_url' => $data['shop']]);
                }

                return $response;
            }
            return ['success' => false, "message" => $response['message']];
        }
        return ['success' => false, "message" => "shop not found"];
    }
}
