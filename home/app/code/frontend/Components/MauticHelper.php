<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
use App\Shopify\Models\Shop\Details;
use App\Connector\Models\User\Connector;
class MauticHelper extends Base
{
	public const mauticServerBaseUrl = 'https://apps.cedcommerce.com/marketplace-integration/mailautomation/mautic-helper/';

	public const emailVerificationUrl = 'http://api.quickemailverification.com/v1/verify';

	public function createMauticContact($sellingOn, $userId = false)
	{
		if (!$userId) {
			$userId = $this->di->getUser()->id;
		}

		$shopDetails = Details::findFirst(["user_id='{$userId}'"]);
		if ($shopDetails) {
			$shopDetails = $shopDetails->toArray();
			$shopData = $this->di->getObjectManager()->get('\App\Shopify\Components\EngineHelper')->getShopDetails($userId, $shopDetails['shop_url']);
			if ($shopData) {
				$shopData['selling_on'] = $sellingOn;
				$validEmail = $this->validateEmail($shopData['customer_email']);
				if (!$validEmail) {
					$shopData['email_not_valid'] = true;
				}

				$serverUrl = self::mauticServerBaseUrl . 'createcontact';
				$response = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->curlRequest($serverUrl, $shopData, false, true, 'POST');
				$updatedData = [];
				$updatedData[$sellingOn . '_installed'] = 'yes';
				$updatedData[$sellingOn . '_uninstalled'] = 'no';
				$userConnector = Connector::findFirst(["user_id='{$userId}' AND code='shopify'"]);
				if ($userConnector) {
					$updatedData[$sellingOn . '_installation_date'] = date('Y-m-d', strtotime($userConnector->installed_at));
				}

				$this->updateMauticContact($updatedData, $userId);
				return ['success' => true, 'message' => 'Mautic contact created successfully'];
			}

			return ['success' => false, 'data' => ['Failed to fetch Shop json']];
		}

		return ['success' => false, 'message' => 'Shop details not found'];
	}

	public function updateMauticContact($updatedData, $userId = false, $shopUrl = false)
	{
		if (!$userId) {
			$userId = $this->di->getUser()->id;
		}

		if ($shopUrl) {
			$updatedData['shop_name'] = $shopUrl;
			$serverUrl = self::mauticServerBaseUrl . 'updatecontact';
			$response = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->curlRequest($serverUrl, $updatedData, false, true, 'POST');
			return ['success' => true, 'message' => 'Mautic contact updated successfully'];
		}
  $shopDetails = Details::findFirst(["user_id='{$userId}'"]);
  if ($shopDetails) {
				$shopDetails = $shopDetails->toArray();
				$shopData = $this->di->getObjectManager()->get('\App\Shopify\Components\EngineHelper')->getShopDetails($userId, $shopDetails['shop_url']);
				if ($shopData && isset($shopData['myshopify_domain'])) {
					$updatedData['shop_name'] = $shopData['myshopify_domain'];
					$serverUrl = self::mauticServerBaseUrl . 'updatecontact';
					$response = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->curlRequest($serverUrl, $updatedData, false, true, 'POST');
					return ['success' => true, 'message' => 'Mautic contact updated successfully'];
				}

				return ['success' => false, 'data' => ['Failed to fetch Shop json']];
			}
  return ['success' => false, 'message' => 'Shop details not found'];
	}

	public function validateEmail($email)
	{
		if ($this->di->getConfig()->get('mail_verification')) {
			$apiKey = $this->di->getConfig()->mail_verification->API_KEY;
			$baseUrl = self::emailVerificationUrl;
			$finalUrl = $baseUrl . '?email=' . $email . '&apikey=' . $apiKey;
			$response = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->curlRequest($finalUrl, [], false, true, 'GET');
			if ($response && isset($response['message'])) {
				$validationResponse = json_decode((string) $response['message'], true);
				if ($validationResponse['result'] == 'invalid' || $validationResponse['disposable'] == 'true') {
					return false;
				}
			}
		}

		return true;
	}
}