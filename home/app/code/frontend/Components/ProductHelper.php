<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
class ProductHelper extends Base {

    /**
     * Change marketplace attribute key.
     *
     * @param array $marketPlaceAttributes array containing the data to change keys.
     * @since 1.0.0
     */
    public function changeKeysForMongo( &$marketPlaceAttributes ): void {
        $final = [];
        if (is_array( $marketPlaceAttributes )) {
            $final = [];
            foreach($marketPlaceAttributes as $k=>&$v) {
                $k = str_replace( ['.', '$'], ['\u002e', '\u0024'], $k);
                $this->changeKeysForMongo( $v );
                $final[$k] = $v;
            }

            $marketPlaceAttributes = $final;
        } elseif ( is_string( $marketPlaceAttributes ) ) {
            $marketPlaceAttributes = trim($marketPlaceAttributes);
        }
    }

    /**
     * Will save product attributes.
     *
     * @param array $params array containing the request params.
     * @since 1.0.0
     * @return array
     */
    public function saveProductAttibutes( $params ) {
        if ( isset( $params['container_id'] ) ) {
            $mongo      = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable( "product_container" );
            $query      = [
                'user_id'            => $this->di->getUser()->id ?? false,
                'container_id'       => (string) $params['container_id'],
                'source_marketplace' => $this->di->getRequester()->getSourceName() ?? false,
            ];
            if ( ! empty( $params['marketplace_attributes'] ) && is_array( $params['marketplace_attributes'] ) ) {
                $this->changeKeysForMongo( $params['marketplace_attributes'] );
            }

            $set = [
                '$set' => [
                    "global_attributes"      => ( $params['global_attributes'] ?? false ),
                    "marketplace_attributes" => [ $params['marketplace_attributes'] ],
                ]
            ];
            if ( ! empty( $params['attribute_value_mappings'] ) && is_array( $params['attribute_value_mappings'] ) ) {
                $this->updateValueMappingSchema( $params['attribute_value_mappings'] );
                $set['$set']['attribute_value_mappings'] = $params['attribute_value_mappings'];
            }

            if ( ! empty( $params['global_attributes'] ) ) {
                $set = [
                    $set,
                    [
                        '$unset' => [ 'profile_changed', 'marketplace_attributes' ],
                    ]
                ];
            } elseif ( ! empty( $params['notification_hide'] ) ) {
                $set['$set']['profile_changed'] = false; 
            }

            $collection->updateMany($query, $set);
            return [ 'success' => true, 'code' => 'product_attributes_modified', 'message' => $this->di->getLocale()->_( 'Product Attributes Saved Successfully' ) ];
        }
        return [ 'success' => false, 'message' => $this->di->getLocale()->_( 'Missing container_id' ) ];
    }

    /**
     * Will update value mapping schema.
     *
     * @param array $valueMappingsData will update schema of value mapping for saving in DB.
     * @since 1.0.0
     */
    public function updateValueMappingSchema( &$valueMappingsData ): void {
        $finalData = [];
        foreach ( $valueMappingsData as $optionMapping ) {
            $values = $optionMapping['value'] ?? [];
            $data   = [];
            foreach ( $values as $value ) {
                $key    = key( $value );
                $data[] = [
                    'key'   => $key,
                    'value' => $value[ $key ] ?? ''
                ];
            }

            $finalData[] = [
                'key'   => $optionMapping['key'] ?? '',
                'value' => $data
            ];
        }

        $valueMappingsData = $finalData;
    }

    /**
     * Will fetch variant attributes from DB.
     *
     * @param array $params array containing the request params.
     * @since 1.0.0
     * @return array
     */
    public function getVariantAttributes( $params = [] ) {
        if ( empty( $params['variant_attributes'] ) || ! is_array( $params['variant_attributes'] ) ) {
            return [
                'success' => false,
                'msg'     => 'Variant attributes values required.',
                'params'  => $params
            ];
        }

        $productContainer    = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')
                            ->getCollectionForTable( 'product_container' );
        $userId              = $this->di->getUser()->id ?? false;
        $sourceShopId        = $this->di->getRequester()->getSourceId() ?? false;
        $requestVariantAttr  = $params['variant_attributes'];
        $variationAttributes = $productContainer->find(
            [
                'user_id'                       => $userId,
                'shop_id'                       => (string) $sourceShopId,
                'type'                          => 'variation',
                'visibility'                    => 'Catalog and Search',
                'variant_attributes_values.key' => [ '$in' => $requestVariantAttr ]
            ],
            [
                'projection' => [
                    'variant_attributes_values' => true,
                    '_id'                       => false
                ]
            ]
        );
        if ( is_null( $variationAttributes ) ) {
            return [
                'success' => false,
                'msg'     => 'Variant Attributes not found.'
            ];
        }

        $variationAttributes = json_decode( toJSON( fromPHP( $variationAttributes->toArray() ) ), true );
        $resAttr             = [];
        foreach ( $variationAttributes as $variationAttribute ) {
            if ( ! isset( $variationAttribute['variant_attributes_values'] ) || ! is_array( $variationAttribute['variant_attributes_values'] ) ) {
                continue;
            }

            foreach ( $variationAttribute['variant_attributes_values'] as $variantAttr ) {
                if ( ! isset( $variantAttr['key'], $variantAttr['value'] ) ) {
                    continue;
                }

                if ( ! in_array( $variantAttr['key'], $requestVariantAttr ) ) {
                    continue;
                }

                $resAttr[ $variantAttr['key'] ] = array_merge( ( $resAttr[ $variantAttr['key'] ] ?? [] ), $variantAttr['value'] );
            }
        }

        $resAttr = array_map(
            function ( $attr ): array {
                $attr = array_unique( $attr );
                return array_values( $attr );
            },
            $resAttr
        );
        return [
            'success' => true,
            'data'    => $resAttr
        ];
    }
}