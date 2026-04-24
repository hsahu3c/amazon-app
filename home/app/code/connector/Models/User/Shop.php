<?php

namespace App\Connector\Models\User;

use Exception;
use App\Core\Models\User\Details;

class Shop extends Details
{
	protected $table = 'user_details';

	protected $isGlobal = true;

	public function addApp($remoteShopId, $app, $userId = false)
	{
		if (!$userId) {
			$userId = $this->di->getUser()->id;
		}

		$userName = $this->di->getUser()->username;
		$email = $this->di->getUser()->email;
        $backupResponse =$this->isBackupPresent($userName, $email, $app['code']);
		$user = $this->getCollection();

		$out = $user->aggregate([
			['$match' => [
				'shops.remote_shop_id' => (string)$remoteShopId,
				'shops.apps.code' => $app['code'],
			]],
			['$unwind' => '$shops'],
			['$unwind' => '$shops.apps'],
			['$match' => [
				'shops.remote_shop_id' => (string)$remoteShopId,
				'shops.apps.code' => $app['code'],
			]],
			['$project' => ['shops.apps.code'  => 1]]
		])->toArray();

		if ($backupResponse['success']){
			$app['reinstalled']= true;
			$app['reinstalled_at'] = new \MongoDB\BSON\UTCDateTime();
		}

		if (empty($out)) {
			$user->updateOne(
				[
					"_id" => new \MongoDB\BSON\ObjectId($userId)
				],
				[
					'$push' => [
						'shops.$[shop].apps' => $app
					]
				],
				[
					'arrayFilters' => [
						[
							'shop.remote_shop_id' => $remoteShopId
						]
					]
				]
			);
			return true;
		}

		return false;
	}


	public function addWebhook($shop, $appCode, $webhook, $userId = false)
	{
		$userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($shop['_id'], $this->di->getUser()->id, true);
		if (isset($shop['remote_shop_id'])) {
			if (isset($shop['apps'])) {
				$webhooNeedToSet = [];
				$checkKeys = [];
				foreach ($shop['apps'] as $app) {
					if ($app['code'] == $appCode) {

						if (isset($app['webhooks'])) {
							foreach ($app['webhooks'] as $appWebhook) {
								if (!isset($checkKeys[$appWebhook['code']])) {
									$prepareWebhookData = [
										'code' => $appWebhook['code'], 
										'dynamo_webhook_id' => $appWebhook['dynamo_webhook_id'] ?? '',
									];
									//Handled case to not save null value in marketplace_subscription_id
									if(!empty($appWebhook['marketplace_subscription_id'])) {
										$prepareWebhookData['marketplace_subscription_id'] = $appWebhook['marketplace_subscription_id'];
									}
									$webhooNeedToSet[] = $prepareWebhookData;
									$checkKeys[$appWebhook['code']] = 1;
								}
							}
						}

						foreach ($webhook as $webhookId => $webhookData) {
							if (!isset($checkKeys[$webhookId])) {
								$webhooNeedToSet[] = [
									'code' => $webhookId, 
									'dynamo_webhook_id' => $webhookData['dynamo_webhook_id'] ?? '',
									'marketplace_subscription_id' => $webhookData['marketplace_subscription_id'] ?? null
								];
								$checkKeys[$webhookId] = 1;
							}
						}
					}
				}
			}

			$remoteShopId = $shop['remote_shop_id'];

			if (!$userId) {
				$userId = $this->di->getUser()->id;
			}

			$user = $this->getCollection();



			return $user->updateOne(
				[
					"_id" => new \MongoDB\BSON\ObjectId($userId),
				],
				[
					'$set' => [
						'shops.$[shop].apps.$[app].webhooks' => $webhooNeedToSet
					]
				],
				[
					'arrayFilters' => [
						[
							'shop.remote_shop_id' => $remoteShopId,
						],
						[
							'app.code' => $appCode
						]
					]
				]

			);
		}
	}

	
	
}
