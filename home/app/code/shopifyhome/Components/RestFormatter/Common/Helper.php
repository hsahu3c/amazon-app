<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\RestFormatter\Common;

use App\Shopifyhome\Components\Core\Common;

class Helper extends Common {
    
    public function camelCaseToSnakeCaseArray($arr)
    {
        $result = [];
        foreach ($arr as $key => $value) {
            $snakeCaseKey = preg_replace_callback('/([a-z])([A-Z])/', fn($matches) => strtolower((string) $matches[1]) . '_' . strtolower((string) $matches[2]), $key);
            if (is_array($value)) {
                $result[$snakeCaseKey] = $this->camelCaseToSnakeCaseArray($value);
            } else {
                $result[$snakeCaseKey] = $value;
            }
        }

        return $result;
    }

    public function extractId($id)
    {
        if (empty($id)) {
            return null;
        }

        $parts = explode('/', (string) $id);
        $filteredParts = array_filter($parts);
        return end($filteredParts);
    }
}