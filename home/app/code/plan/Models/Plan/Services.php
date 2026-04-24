<?php

namespace App\Plan\Models\Plan;

use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;

/**
 * Class Services
 * @package App\Plan\Models\Plan
 * for hanlding product import services
 */
class Services extends \App\Core\Models\BaseMongo
{

	public const SERVICE_TYPE_PRODUCT_IMPORT = "product_import";
	public const SERVICE_TYPE_ACCOUNT_CONNECTION = "account_connection";
	public const SERVICE_TYPE_TEMPLATE_CREATION = "template_creation";



	public function updateCredits($serviceType = "", $userId = false, $shopId = "", $count = 0, $action = "add", $pendingInBulk = 0)
	{
		if ($serviceType == self::SERVICE_TYPE_PRODUCT_IMPORT) {
			return $this->manageProductImportCredits($userId, $shopId, $count, $action, $pendingInBulk);
		}
	}

	public function manageProductImportCredits($userId, $shopId, $count = 0, $action = "add", $pendingInBulk = 0)
	{
		$freshUpdated = false;
		$cacheRefreshTime = $this->di->getConfig()->get("supported_services")[self::SERVICE_TYPE_PRODUCT_IMPORT]['cache_refresh_time'] ?? 3600;
		
		/**
		 * during cache update we need not to add the coming count as we are actually fetching the original count of the products from the container
		 */
		if ($this->di->getCache()->has("import_restrictions_" . $userId, "plan")) {
			$cachedData = $this->di->getCache()->get("import_restrictions_" . $userId, "plan");
			if (isset($cachedData['used_count']) && isset($cachedData['limit']) && isset($cachedData['sync_activated'])) {
				$updatedCount = 0;
				$updatedCount = ($action == "deduct") ? ($cachedData['used_count'] - $count) : ($cachedData['used_count'] + $count);
				if (($cachedData['used_count'] > $updatedCount) && ($updatedCount < $cachedData['limit'])
					|| ($cachedData['used_count'] < $updatedCount) && ($updatedCount >= $cachedData['limit'])
				) {
					$freshUpdated = true;
				} else {
					$cachedData['used_count'] = $updatedCount;
					if ($cachedData['used_count'] >= $cachedData['limit']) {
						$cachedData['sync_activated'] = false;
					} else {
						$cachedData['sync_activated'] = true;
					}
				}
			} else {
				$freshUpdated = true;
			}
		} else {
			$freshUpdated = true;
		}

		if ($freshUpdated) {
			//we are not performing any updates in this as here the intialization will be direct from db so it will be updated already
			$this->updateCacheAndManageSyncing($userId, $shopId, $cacheRefreshTime, $pendingInBulk);
			$cachedData = $this->di->getCache()->get("import_restrictions_" . $userId, "plan");
		} elseif(!empty($cachedData)) {
			$this->di->getCache()->set("import_restrictions_" . $userId, $cachedData, "plan", $cacheRefreshTime);
		}

		$success = true;
		if (!empty($cachedData) && ($cachedData['used_count'] >= $cachedData['limit'])) {
			$success = false;
		}

		if ($success) {
			$message = 'Product import limit processed successfully!';
		} else {
			$message = 'Product import limit failed to update!';
		}

		return [
			'success' => $success,
			'message' => $message
		];
	}

	public function updateCacheAndManageSyncing($userId, $shopId, $cacheRefreshTime,$pendingInBulk = 0)
	{
		$baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
		$paymentCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
		$serviceQuery = [
			'user_id' => (string)$userId,
			'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
			'source_marketplace' => Plan::SOURCE_MARKETPLACE,
			'target_marketplace' => Plan::TARGET_MARKETPLACE,
			'marketplace' => Plan::TARGET_MARKETPLACE,
			'service_type' => self::SERVICE_TYPE_PRODUCT_IMPORT
		];
		$productImportService = $paymentCollection->findOne($serviceQuery, Plan::TYPEMAP_OPTIONS);
		if (!empty($productImportService) && (!isset($productImportService['active']) || (isset($productImportService['active']) && $productImportService['active']))) {
			$productContainer = $baseMongo->getCollectionForTable('product_container');
			$parentProductQuery = [
				'user_id' => $userId,
				'shop_id' => $shopId,
				"visibility" => "Catalog and Search"
			];
			$productCount = $productContainer->countDocuments($parentProductQuery);
			$productCount = $productCount + $pendingInBulk;

			$syncActive = true;
			if ($productCount >= $productImportService['prepaid']['service_credits']) {
				$syncActive = false;
			}

			$updated = false;
			if (!isset($productImportService['sync_activated'])) {
				$updated = true;
				$productImportService['sync_activated'] = $syncActive;
			} elseif ($productImportService['sync_activated'] !== $syncActive) {
				$updated = true;
				$productImportService['sync_activated'] = $syncActive;
			}

			if (!($productImportService['prepaid']['total_used_credits'] == $productCount)) {
				$updated = true;
				$productImportService['prepaid']['total_used_credits'] = $productCount;
				$productImportService['prepaid']['available_credits'] = ($productImportService['prepaid']['service_credits'] - $productCount);
			}

			if ($updated) {
				$productImportService['updated_at'] = date('Y-m-d H:i:s');
				$paymentCollection->replaceOne(
					$serviceQuery,
					$productImportService,
					['upsert' => true]
				);
			}

			$cachedData = ['used_count' => $productCount, 'limit' => $productImportService['prepaid']['service_credits'], 'sync_activated' => $syncActive];
			$this->di->getCache()->set("import_restrictions_" . $userId, $cachedData, "plan", $cacheRefreshTime);
			return true;
		}

		return false;
	}

	public function handleServicesRemoved($serviceType = "", $userId = false): void
	{
		if (!empty($serviceType)) {
			if ($serviceType == self::SERVICE_TYPE_PRODUCT_IMPORT) {
				if ($this->di->getCache()->has("import_restrictions_" . $userId, "plan")) {
					$this->di->getCache()->delete("import_restrictions_" . $userId, "plan");
				}
			}
		}
	}

	public function handleServicesUpdate($updatedServices = []): void
	{
		foreach($updatedServices['services'] as $type => $updatedService) {
			if ($type == self::SERVICE_TYPE_PRODUCT_IMPORT && isset($updatedService['active']) && ($updatedService['active'] == false)) {
				if ($this->di->getCache()->has("import_restrictions_" . $updatedServices['user_id'], "plan")) {
					$this->di->getCache()->delete("import_restrictions_" . $updatedServices['user_id'], "plan");
				}
			}

			if ($type == self::SERVICE_TYPE_ACCOUNT_CONNECTION) {
				$this->updateCreditsAsPerServiceType($type, $updatedService);
			}

			if($type == self::SERVICE_TYPE_TEMPLATE_CREATION) {
				$this->updateCreditsAsPerServiceType($type, $updatedService);
			}
		}
	}

	public function getServiceCredits($planDetails, $type = Plan::SERVICE_TYPE_ORDER_SYNC)
	{
		if (!empty($planDetails['services_groups'])) {
			foreach($planDetails['services_groups'] as $serviceGroups) {
				foreach($serviceGroups['services'] as $serviceType) {
					if(($serviceType['type'] === $type) && !empty($serviceType['prepaid'])) {
						if (isset($serviceType['prepaid']['service_credits'])) {
							return (int)$serviceType['prepaid']['service_credits'];
						}
					}
				}
			}
        }
		return 0;
	}

	public function getServiceData($planDetails, $type = Plan::SERVICE_TYPE_ORDER_SYNC)
	{
		if (!empty($planDetails['services_groups'])) {
			foreach($planDetails['services_groups'] as $serviceGroups) {
				foreach($serviceGroups['services'] as $serviceType) {
					if(($serviceType['type'] === $type)) {
						return $serviceType;
					}
				}
			}
        }
		return 0;
	}

	public function updateCreditsAsPerServiceType($serviceType, $serviceData = []) {
		$userId = $this->di->getUser()->id;
		switch($serviceType) {
			case self::SERVICE_TYPE_ACCOUNT_CONNECTION:
				// Running query here to get the updated shops of the user
				$shops = $this->getUserShops($userId);
				return $this->manageAccountConnectionCredits($userId, $shops, $serviceData);
			case self::SERVICE_TYPE_TEMPLATE_CREATION:
				return $this->manageProfileCreationCredits($userId, $serviceData);
			default:
				break;
		}
	}

	private function getUserShops($userId)
	{
		$baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
		$userDetails = $baseMongo->getCollectionForTable(Plan::USER_DETAILS_COLLECTION_NAME);
		$userDetails = $userDetails->findOne(['user_id' => (string)$userId], ['projection' => ['shops' => 1]]);
		return $userDetails['shops'] ?? [];
	}

	private function manageAccountConnectionCredits($userId, $shops, $serviceData)
	{
		$baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
		$paymentCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
		$serviceQuery = [
			'user_id' => (string)$userId,
			'service_type' => self::SERVICE_TYPE_ACCOUNT_CONNECTION
		];

		if (empty($serviceData)) {
			$serviceData = $paymentCollection->findOne($serviceQuery, Plan::TYPEMAP_OPTIONS) ?? [];
		}

		if (empty($serviceData)) {
			return;
		}

		$prepaidCredits = $serviceData['prepaid'] ?? [];
		$serviceCredits = (int)($prepaidCredits['service_credits'] ?? 0);

		$totalActiveTargetShops = $this->getTotalActiveTargetShops($shops);

		if ($totalActiveTargetShops > $serviceCredits) {
			$totalUsedCredits = $serviceCredits;
			$totalAvailableCredits = $totalUsedCredits - $totalActiveTargetShops;
		} else {
			$totalUsedCredits = $totalActiveTargetShops;
			$totalAvailableCredits = $serviceCredits - $totalUsedCredits;
		}

		$finalAccountCredits = [
			'service_credits' => $serviceCredits,
			'available_credits' => $totalAvailableCredits,
			'total_used_credits' => $totalUsedCredits
		];
		$paymentCollection->updateOne(
			$serviceQuery,
			['$set' => ['prepaid' => $finalAccountCredits, 'active' => true, 'updated_at' => date('Y-m-d H:i:s')]]
		);

		// Check if over exceed case is over and unset auto_disconnect_account_after
		$prepaidAvailableCredits = $prepaidCredits['available_credits'] ?? 0;
		if ($prepaidAvailableCredits < 0 && $finalAccountCredits['available_credits'] >= 0) {
			$userDetailsCollection = $baseMongo->getCollectionForTable(Plan::USER_DETAILS_COLLECTION_NAME);
			$userDetailsCollection->updateOne(
				['user_id' => (string)$userId],
				[
					'$unset' => [
						'auto_disconnect_account_after' => 1,
						'shops.$[shop].auto_disconnect_account_after' => 1
					],
					'$set' => [
						'account_restriction_resolved_at' => date('c')
					]
				],
				[
					'arrayFilters' => [
						['shop.marketplace' => Plan::TARGET_MARKETPLACE, 'shop.auto_disconnect_account_after' => ['$exists' => true]]
					]
				]
			);
		}
	}

	private function getTotalActiveTargetShops($shops)
	{
		$totalActiveTargetShops = 0;
		if (!empty($shops)) {
			foreach ($shops as $shop) {
				$marketplace = $shop['marketplace'] ?? '';
				$apps = $shop['apps'] ?? [];
				$appStatus = isset($apps[0]['app_status']) ? $apps[0]['app_status'] : '';
				if ($marketplace === Plan::TARGET_MARKETPLACE && $appStatus === 'active') {
					$totalActiveTargetShops++;
				}
			}
		}
		return $totalActiveTargetShops;
	}

	private function manageProfileCreationCredits($userId, $serviceData)
	{
		$baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
		$paymentCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
		$serviceQuery = [
			'user_id' => (string)$userId,
			'service_type' => self::SERVICE_TYPE_TEMPLATE_CREATION
		];

		if (empty($serviceData)) {
			$serviceData = $paymentCollection->findOne($serviceQuery, Plan::TYPEMAP_OPTIONS) ?? [];
		}

		if (empty($serviceData)) {
			return;
		}

		$prepaidCredits = $serviceData['prepaid'] ?? [];
		$serviceCredits = (int)($prepaidCredits['service_credits'] ?? 0);

		$totalActiveProfiles = $this->getTotalActiveProfiles($userId);

		if ($totalActiveProfiles > $serviceCredits) {
			$totalUsedCredits = $serviceCredits;
			$totalAvailableCredits = $totalUsedCredits - $totalActiveProfiles;
		} else {
			$totalUsedCredits = $totalActiveProfiles;
			$totalAvailableCredits = $serviceCredits - $totalUsedCredits;
		}

		$finalProfileCredits = [
			'service_credits' => $serviceCredits,
			'available_credits' => $totalAvailableCredits,
			'total_used_credits' => $totalUsedCredits
		];
		$paymentCollection->updateOne(
			$serviceQuery,
			['$set' => ['prepaid' => $finalProfileCredits, 'active' => true, 'updated_at' => date('Y-m-d H:i:s')]]
		);

		// Check if over exceed case is over and unset auto_remove_extra_templates_after
		$prepaidAvailableCredits = $prepaidCredits['available_credits'] ?? 0;
		if ($prepaidAvailableCredits < 0 && $finalProfileCredits['available_credits'] >= 0) {
			$userDetailsCollection = $baseMongo->getCollectionForTable(Plan::USER_DETAILS_COLLECTION_NAME);
			$userDetailsCollection->updateOne(
				['user_id' => (string)$userId],
				[
					'$unset' => [
						'auto_remove_extra_templates_after' => 1,
						'shops.$[shop].auto_remove_extra_templates_after' => 1
					],
					'$set' => [
						'template_restriction_resolved_at' => date('c')
					]
				],
				[
					'arrayFilters' => [
						['shop.marketplace' => Plan::TARGET_MARKETPLACE, 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
					]
				]
			);
		}
	}

	private function getTotalActiveProfiles($userId)
	{
		$baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
		$profileCollection = $baseMongo->getCollectionForTable('profile');
		$profileQuery = [
			'user_id' => (string)$userId,
			'type' => 'profile'
		];
		$totalActiveProfiles = $profileCollection->countDocuments($profileQuery);
		return $totalActiveProfiles ?? 0;
	}
}