<?php


namespace App\Amazon\Controllers;
use App\Connector\Components\ApiClient;
use App\Amazon\Components\Feed;
use App\Amazon\Components\Order;

class AmazontaskController extends \App\Core\Controllers\BaseController
{
    public function priceUploadAction()
    {
        try {
            $marketplace = ['A21TJRUUN4KGV'];
            $shopId = 5;
            $feedContent = [
                '693' => [
                    'Id' => 246,
                    'SKU' => '693',
                    'Sale' => false,
                    'SalePrice' => 32,
                    'StandardPrice' => 32,
                    'StartDate' => '',
                    'EndDate' => '',
                    'BusinessPrice' => '',
                    'MinimumPrice' => 32
                ]
            ];
            $params = [
                'marketplace_id' => $marketplace,
                'shop_id' => $shopId,
                'feedContent' => $feedContent
            ];
            $response = $this->di->getObjectManager()
                ->get(ApiClient::class)
                ->init('amazon', 'true')
                ->call('price-upload', [], $params, 'POST');
            $feed = $this->di->getObjectManager()
                ->get(Feed::class)
                ->init(['user_id' => 3, 'shop_id' => $shopId])
                ->process($response);
            print '<pre>';
            print_r($response);
            die();
        } catch (\Exception $e) {
            // die($e->getMessage());
        }
    }

    public function orderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order')->init(['shop_id' => $rawBody['accounts']])->fetchOrders($rawBody);
        return $this->prepareResponse($response);
    }

    public function shipOrderAction()
    {
        try {
            $amazonOrderId = ['203-4444120-7245156'];
            $this->di->getObjectManager()->get('\App\Amazon\Components\Order')
                ->init(['shop_id' => 8, 'user_id' => 3])
                ->shipOrders($amazonOrderId);
            die();
        } catch (\Exception $e) {
            // die($e->getMessage());
        }
        $feedContent = [
            415222310620 => [
                'Id' => 415222310620,
                'AmazonOrderID' => '305-3426836-5476344',
                'FulfillmentDate' => date('Y-m-d H:i:s P', strtotime('2020-07-17')),
                'FulfillmentData' => [
                    'CarrierCode' => 'GLS',
                    'ShippingMethod' => 'GLS',
                    'ShipperTrackingNumber' => '51152351179'
                ],
                'Item' => [
                    [
                        'AmazonOrderItemCode' => '03887305709571',
                        'Quantity' => 3
                    ]
                ]
            ]
        ];
        $marketplace = ['A13V1IB3VIYZZH'];
        $shopId = 5;
        $params = ['feedContent' => $feedContent, 'marketplace_id' => $marketplace, 'shop_id' => $shopId];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('ship-order', [], $params, 'POST');
        print '<pre>';
        print_r($response);
        die();
    }

    public function getProductAction()
    {
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('product', [], ['shop_id' => 5, 'id' => 'BTDG-40S-BLK', 'id_type' => 'SellerSKU'], 'GET');
        print '<pre>';
        print_r($response);
        die();
    }

    public function uploadProductAction()
    {
        $feedContent = [
            '27367-1' => [
                'Id' => 246,
                'SKU' => '27367-1',
                'StandardProductID_Value' => '8017673031844',
                'DescriptionData_Title' => 'UNITERS Wood Care Kit - 225 ML',
                'DescriptionData_Brand' => 'UNITERS',
                'DescriptionData_Description' => 'this is a kind of good details.'
            ]
        ];
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 5;
        $params = ['feedContent' => $feedContent, 'marketplace' => $marketplace, 'shop_id' => $shopId];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('product-upload', [], $params, 'GET');

        print '<pre>';
        print_r($response);
        die();
    }

    public function inventoryUploadAction()
    {
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 5;
        $feedContent = [
            '693' => [
                'Id' => 246,
                'SKU' => '693',
                'Quantity' => 100,
                'Latency' => 1
            ]
        ];
        $params = [
            'marketplace_id' => $marketplace,
            'shop_id' => $shopId,
            'feedContent' => $feedContent
        ];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('inventory-upload', [], $params, 'POST');
        print '<pre>';
        print_r($response);
        die();
    }

    public function deleteProductAction()
    {
        $feedContent = [
            '27367-1' => [
                'Id' => 246,
                'SKU' => '27367-1',
            ]
        ];
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 5;
        $params = ['feedContent' => $feedContent, 'marketplace' => $marketplace, 'shop_id' => $shopId];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('product-delete', [], $params, 'GET');
        print '<pre>';
        print_r($response);
        die();
    }

    public function syncFeedAction()
    {
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 5;
        $params = ['feed_id' => '53487018587', 'marketplace_id' => $marketplace, 'shop_id' => $shopId];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('feed-sync', [], $params, 'GET');
        print '<pre>';
        print_r($response);
        die();
    }

    public function imageUploadAction()
    {
        $feedContent = [
            4681 => [
                'Id' => 4681,
                'SKU' => 'MH01',
                'ImageType' => 'PT1',
                'Url' => 'https://m235.cedmagento.com/media/catalog/product/m/h/mh01-gray_main_1.jpg'
            ],
            4682 => [
                'Id' => 4682,
                'SKU' => 'MH01',
                'ImageType' => 'PT2',
                'Url' => 'https://m235.cedmagento.com/media/catalog/product/m/h/mh01-gray_alt1_1.jpg'
            ],
            4683 => [
                'Id' => 4683,
                'SKU' => 'MH01-XS-Black',
                'ImageType' => 'Main',
                'Url' => 'https://m235.cedmagento.com/media/catalog/product/m/h/mh01-gray_main_1.jpg'
            ],
            4684 => [
                'Id' => 4684,
                'SKU' => 'MH01-XS-Gray',
                'ImageType' => 'PT1',
                'Url' => 'https://m235.cedmagento.com/media/catalog/product/m/h/mh01-gray_alt1_1.jpg'
            ],
            4685 => [
                'Id' => 4685,
                'SKU' => 'MH01-XS-Gray',
                'ImageType' => 'Main',
                'Url' => 'https://m235.cedmagento.com/media/catalog/product/m/h/mh01-gray_main_1.jpg'
            ]
        ];
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 5;
        $params = ['feedContent' => $feedContent, 'marketplace_id' => $marketplace, 'shop_id' => $shopId];
        $response = $this->di->getObjectManager()
            ->get(ApiClient::class)
            ->init('amazon', 'true')
            ->call('image-upload', [], $params, 'POST');
        print '<pre>';
        print_r($response);
        die();
    }
public function leadAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $requester = $this->di->getRequester();
        $config = $this->di->getConfig()->lead_form_config->toArray();

        $response = $this->sendToSlack($rawBody, $config);
        $response = $this->sendLeadMail($rawBody);
        return $this->prepareResponse($response);
    }

    public function sendToSlack($data, $config = []) {

        if ( empty($config) ) {
            return null;
        }

        // CC user IDs
        $cc_users = $config['slack']['user_cc'];

        // Build the message
        $message = "hello <!subteam^" . $config['slack']['group_id'] . "|@amazon-bda> \n\n :alert:  *New Query Received From: {$data['clickFrom']}* :alert:\n\n";
        $message .= "*Details:*\n";

        foreach ($data as $key => $value) {
            $message .= "• *" . ucfirst($key) . ":* {$value}\n";
        }

        $message .= "\nCC: ";
        foreach ($cc_users as $user) {
            $message .= "<@{$user}> ";
        }

        // Send to Slack
        $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
            ->call($config['slack']['url'], [], ['text' => $message, 'channel' => $config['slack']['group_id']], 'POST', 'json');
    }

    public function sendLeadMail($data){

        // print_r($data);die;
        if(!isset($data['email'])){
        return ['success'=>false,'message'=>'Email is required'];

        }
        // $emailData['email'] = 'anssingh@threecolts.com';
        $emailData['email'] = 'support@cedcommerce.com';
        $emailData['subject'] = 'Lead For Amazon';
        $emailData['bccs'] = ["kgupta@threecolts.com", "adogra@threecolts.com", 'anssingh@threecolts.com'];//$this->di->getConfig()->allowed_ip_mail;
        $emailData['replyTo']['replyToEmail']=$data['email'];
        $emailData['replyTo']['replyToName']=$data['firstName']??$data['first_name'];

        $emailData['path'] =  'amazon' . DS . 'view' . DS . 'email' . DS . 'leadmail.volt.php';
        $emailBody = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
        $emailBody .= '<tr><th>Key</th><th>Value</th></tr>'; // Table header
        
        foreach ($data as $key => $value) {
            $emailBody .= "<tr>
                            <td style='font-weight: bold;'>$key</td>
                            <td>$value</td>
                          </tr>";
        }

        $emailBody .= "<tr>
                            <td style='font-weight: bold;'>user_id</td>
                            <td>" . $this->di->getUser()->id . "</td>
                          </tr>";
        $emailBody .= "<tr>
                            <td style='font-weight: bold;'>username</td>
                            <td>" . $this->di->getUser()->username . "</td>
                          </tr>";
        $emailBody .= '</table>';
        $emailData['body']=$emailBody;

     
        $sendMailResponse = $this->di->getObjectManager()->create('\App\Core\Components\SendMail')->send($emailData);
        return ['success'=>true,'message'=>'We will connect with you '];

    }
    
}