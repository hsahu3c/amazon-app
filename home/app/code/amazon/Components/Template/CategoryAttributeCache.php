<?php

namespace App\Amazon\Components\Template;

use Exception;
use App\Core\Components\Base;

class CategoryAttributeCache extends Base
{
	private string $_cacheBasePath = BP . DS . 'var' . DS . 'amazon-cache';

	private string $_cacheFileName = 'attributes.txt';

	public const SELLER_WISE_AMAZON_ATTRIBUTES = [
		'brand',
		'merchant_shipping_group',
		'liquid_volume',
		'unit_count'
	];

	public function getAttributesFromCache($marketplaceId, $categoryId, $subCategoryId)
	{
		if (!empty($marketplaceId) && !empty($categoryId) && !empty($subCategoryId)) {
			$filePath = $this->getCacheFile($marketplaceId, $categoryId, $subCategoryId);
			if (file_exists($filePath)) {
				$content = file_get_contents($filePath);
				// $attributes = unserialize($content);
				$attributes = json_decode($content, true);

				if (is_array($attributes)) {
					return $attributes;
				}
				return false;
			}
			return false;
		}
		return false;
	}

	public function getJsonAttributesFromCache($marketplaceId, $productTypes)
	{
		try {
			if (!empty($marketplaceId) && !empty($productTypes)) {
				foreach ($productTypes as $productType) {
					$filePath = $this->getCacheFile($marketplaceId, $productType, false);
					if (file_exists($filePath)) {
						$content = file_get_contents($filePath);
						$attributes = json_decode($content, true);
						return $attributes;
					}
				}
			}
			return false;
		} catch (Exception) {
			return false;
		}
	}

	public function saveAttributesInCache($marketplaceId, $categoryId, $subCategoryId, $attributes)
	{
		if (!empty($marketplaceId) && !empty($categoryId) && !empty($attributes) && is_array($attributes)) {
			$filePath = $this->getCacheFile($marketplaceId, $categoryId, $subCategoryId);

			$dirname = dirname((string) $filePath);
			if (!is_dir($dirname)) mkdir($dirname, 0775, true);
			// $content = serialize($attributes);
			$content = json_encode($attributes);
			$resource = fopen($filePath, 'w');
			fwrite($resource, $content);
			fclose($resource);
			return true;
		}
		return false;
	}

	public function deleteAttributesFromCache($marketplaceId, $categoryId, $subCategoryId)
	{
		if (!empty($marketplaceId) && !empty($categoryId) && !empty($subCategoryId)) {
			//using it to delete the cacheFile for JSON attributes
			if ($subCategoryId == 'JSON') {
				$subCategoryId = false;
			}
			$filePath = $this->getCacheFile($marketplaceId, $categoryId, $subCategoryId);
			if (file_exists($filePath)) {
				return unlink($filePath);
			}
			return false;
		}
		return false;
	}

	public function getCacheFile($marketplaceId, $categoryId, $subCategoryId = null)
	{
		if ($subCategoryId) {
			return $this->_cacheBasePath . DS . $marketplaceId . DS . $categoryId . DS . $subCategoryId . DS . $this->_cacheFileName;
		}
		return $this->_cacheBasePath . DS . $marketplaceId . DS . $categoryId . '.txt';
	}

	public function saveAttributesInDB($remoteResponse, $additionalParams, $schemaContent)
	{
		$preparedData = $this->prepareForDB($remoteResponse, $additionalParams, $schemaContent);

		$jsonCollection = $this->di->getObjectManager()
			->get(\App\Core\Models\BaseMongo::class)
			->getCollectionForTable('product_type_definitions_schema');
		try {
			$jsonCollection->insertOne($preparedData);
		} catch (Exception $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public function getJsonAttributesFromDB($params, $directReturn = false)
	{
		$jsonCollection = $this->di->getObjectManager()
			->get(\App\Core\Models\BaseMongo::class)
			->getCollectionForTable('product_type_definitions_schema');
		// $schemaVersion = $this->di->getConfig()->product_type_definitions_schema_version;
		$options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
		$jsonAttributes = $jsonCollection->findOne(
			[
				'user_id' => $params['user_id'],
				'shop_id' => $params['shop_id'],
				'marketplace_id' => $params['marketplace_id'],
				'product_type' => $params['product_type'],
				// 'product_type_version.version' => $schemaVersion,
				'product_type_version.latest' => true
			],
			$options
		);
		if (empty($jsonAttributes)) {
			return ['success' => false, 'message' => 'schema not found in db'];
		}
		if ($directReturn) {
			return ['success' => true, 'data' => $jsonAttributes];
		}
		$preparedAttributesParams = [
			'schema_content' => json_decode((string) $jsonAttributes['schema'], true),
			'property_groups' => json_decode((string) $jsonAttributes['property_groups'], true),
			'requirements' => $jsonAttributes['requirements']
		];
		$preparedAttributeResponse = $this->prepareJsonAttributes($preparedAttributesParams);
		return !$preparedAttributeResponse['success']
			? $preparedAttributeResponse
			: [
				'success' => true,
				'data' => [
					'attributes' => $preparedAttributeResponse['data'],
					'param' => $preparedAttributeResponse['param'],
					'json_listing' => true,
					'product_type' => $jsonAttributes['product_type'] ?? null,
					'language_tag' => $jsonAttributes['language_tag'],
					'marketplace_id' => $params['marketplace_id'],
				]
			];
	}

	public function getJsonAttributesFromDBV2($params, $directReturn = false)
	{
		$jsonCollection = $this->di->getObjectManager()
			->get('\App\Core\Models\BaseMongo')
			->getCollectionForTable('product_type_definitions_schema');
		$schemaVersion = $this->di->getConfig()->product_type_definitions_schema_version;
		$options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
		$jsonAttributes = $jsonCollection->findOne(
			[
				'user_id' => $params['user_id'],
				'shop_id' => $params['shop_id'],
				'marketplace_id' => $params['marketplace_id'],
				'product_type' => $params['product_type'],
				'product_type_version.version' => $schemaVersion,
			],
			$options
		);
		if (empty($jsonAttributes)) {
			return ['success' => false, 'message' => 'schema not found in db'];
		}
		if ($directReturn) {
			return ['success' => true, 'data' => $jsonAttributes];
		}
		$preparedAttributesParams = [
			'schema_content' => json_decode((string) $jsonAttributes['schema'], true),
			'property_groups' => json_decode((string) $jsonAttributes['property_groups'], true),
			'requirements' => $jsonAttributes['requirements']
		];
		$preparedAttributeResponse = $this->prepareJsonAttributes($preparedAttributesParams);
		return !$preparedAttributeResponse['success']
			? $preparedAttributeResponse
			: [
				'success' => true,
				'data' => [
					'attributes' => $preparedAttributeResponse['data'],
					'param' => $preparedAttributeResponse['param'],
					'json_listing' => true,
					'product_type' => $jsonAttributes['product_type'] ?? null,
					'language_tag' => $jsonAttributes['language_tag'],
					'marketplace_id' => $params['marketplace_id'],
				]
			];
	}

	public function prepareForDB($remoteResponse, $additionalParams, $schemaContent)
	{
		$preparedData = [
			'user_id' => $additionalParams['user_id'],
			'shop_id' => $additionalParams['shop_id'],
			'product_type' => $remoteResponse['productType'],
			'schema' => json_encode($schemaContent),
			'language_tag' => $remoteResponse['locale'],
			'marketplace_id' => $additionalParams['marketplace_id'],
			'product_type_version' => [
				'version' => $remoteResponse['productTypeVersion']['version'],
				'latest' => $remoteResponse['productTypeVersion']['latest'],
				'release_candidate' => $remoteResponse['productTypeVersion']['releaseCandidate'],
			],
			'schema_checksum' => $remoteResponse['schema']['checksum'],
			'created_at' => date('c'),
			'updated_at' => date('c'),
			'property_groups' => json_encode($remoteResponse['propertyGroups']),
			'requirements' => $remoteResponse['requirements'],
			'requirements_enforced' => $remoteResponse['requirementsEnforced'],
		];
		if (isset($remoteResponse['displayName'])) {
			$preparedData['display_name'] = $remoteResponse['displayName'];
		} else {
			$preparedData['display_name'] = ucwords(strtolower(str_replace('_', ' ', $remoteResponse['productType'])));
			// $preparedData['display_name_not_found'] = true;
		}
		if (!empty($additionalParams['is_schema_updated'])) {
			$preparedData['is_schema_updated'] = $additionalParams['is_schema_updated'];
		}
		return $preparedData;
	}

	public function getSchemaContent($schemaUrl)
	{
		$curlResource = curl_init($schemaUrl);
		curl_setopt($curlResource, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curlResource, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curlResource, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curlResource, CURLOPT_SSL_VERIFYHOST, false);
		$response = curl_exec($curlResource);
		if (curl_errno($curlResource)) {
			return [
				'success' => false,
				'error' => 'Error in downloading schema : ' . curl_error($curlResource)
			];
		}
		curl_close($curlResource);
		if (!$response) {
			return [
				'success' => false,
				'message' => 'Something went wrong. Response is empty'
			];
		}
		$schemaResponse = json_decode($response, true);
		return empty($schemaResponse)
			? ['success' => false, 'message' => 'Something went wrong. Response is empty']
			: ['success' => true, 'data' => $schemaResponse];
	}

	public function prepareJsonAttributes($data)
	{
		if (empty($data['schema_content'])) {
			return ['success' => false, 'message' => 'schema_content is required'];
		}
		$schemaContent = $data['schema_content'];
		$propertyGroups = $data['property_groups'] ?? [];
		$requirements = $data['requirements'] ?? 'LISTING';
		$jsonAttributes = [];
		foreach ($propertyGroups as $group => $attributes) {
			if (isset($attributes['propertyNames']) && is_array($attributes['propertyNames'])) {
				foreach ($attributes['propertyNames'] as $attribute) {
					if (isset($schemaContent['properties'][$attribute])) {
						if (($attribute == 'condition_type' && $requirements != 'LISTING') || (in_array($attribute, $schemaContent['required']))) {
							$jsonAttributes[$group]['Mandatory'][$attribute] = $schemaContent['properties'][$attribute];
						} else {
							$jsonAttributes[$group]['Optional'][$attribute] = $schemaContent['properties'][$attribute];
						}
					}
				}
			}
			if (!empty($jsonAttributes[$group])) {
				$jsonAttributes[$group]['title'] = $attributes['title'] ?? null;
				$jsonAttributes[$group]['description'] = $attributes['description'] ?? null;
			}
		}
		return ['success' => true, 'data' => $jsonAttributes, 'param' => $data];
	}

	public function addSellerWiseAttributesIfAvailable(&$data, $additionalParams): void
	{
		$jsonCollection = $this->di->getObjectManager()
			->get('\App\Core\Models\BaseMongo')
			->getCollectionForTable('amazon_seller_attributes');
		$query = [
			'user_id' => $additionalParams['user_id'] ?? $this->di->getUser()->id,
			'marketplace_id' => $additionalParams['marketplace_id']
		];
		$options = ['typeMap'  => ['root' => 'array', 'document' => 'array']];
		$sellerWiseAttributes = $jsonCollection->findOne($query, $options);
		if (empty($sellerWiseAttributes['additional_attributes'])) {
			$fetchSellerWiseAttributesData = [
				'target_shop' => [
					'_id' => $additionalParams['shop_id'],
					"marketplace" => "amazon"
				],
				'user_id' => $additionalParams['user_id'] ?? $this->di->getUser()->id,
			];
			$response = $this->di->getObjectManager()->get('\App\Amazon\Components\ConfigurationEvent')->addSellerWiseProductTypeAttributes($fetchSellerWiseAttributesData);
			if ($response['success']) {
				$sellerWiseAttributes['additional_attributes'] = $response['data'];
			}
		}
		foreach (self::SELLER_WISE_AMAZON_ATTRIBUTES as $additionalAttribute) {
			if (isset($data['attributes']['offer']['Mandatory'][$additionalAttribute], $sellerWiseAttributes['additional_attributes'][$additionalAttribute])) {
				$data['attributes']['offer']['Mandatory'][$additionalAttribute]['items']['properties']['value'] = array_merge($data['attributes']['offer']['Mandatory'][$additionalAttribute]['items']['properties']['value'], $sellerWiseAttributes['additional_attributes'][$additionalAttribute]);
			} elseif (isset($data['attributes']['offer']['Optional'][$additionalAttribute], $sellerWiseAttributes['additional_attributes'][$additionalAttribute])) {
				$data['attributes']['offer']['Optional'][$additionalAttribute]['items']['properties']['value'] = array_merge($data['attributes']['offer']['Optional'][$additionalAttribute]['items']['properties']['value'], $sellerWiseAttributes['additional_attributes'][$additionalAttribute]);
			} elseif (isset($data['attributes']['safety_and_compliance']['Optional'][$additionalAttribute], $sellerWiseAttributes['additional_attributes'][$additionalAttribute])) {
				$data['attributes']['safety_and_compliance']['Optional'][$additionalAttribute]['items']['properties']['value'] = array_merge($data['attributes']['safety_and_compliance']['Optional'][$additionalAttribute]['items']['properties']['value'], $sellerWiseAttributes['additional_attributes'][$additionalAttribute]);
			} elseif (isset($data['attributes']['product_details']['Optional'][$additionalAttribute], $sellerWiseAttributes['additional_attributes'][$additionalAttribute])) {
				$data['attributes']['product_details']['Optional'][$additionalAttribute]['items']['properties']['value'] = array_merge($data['attributes']['product_details']['Optional'][$additionalAttribute]['items']['properties']['value'], $sellerWiseAttributes['additional_attributes'][$additionalAttribute]);
			} else {
				if ($additionalAttribute != 'merchant_shipping_group') {
					unset($data['attributes']['offer']['Mandatory'][$additionalAttribute]);
					unset($data['attributes']['offer']['Optional'][$additionalAttribute]);
				}
			}
		}
	}
}
