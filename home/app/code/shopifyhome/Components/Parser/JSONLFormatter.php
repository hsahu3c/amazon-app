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
 * @package     Ced_Shopifynxtgen
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Parser;

use App\Shopifyhome\Components\Parser\Exception\JSONLFormatterException;

class JSONLFormatter
{
    private $_formattedData;

    private $_paths = [];

    public function setItem($item): void
    {
        if(is_null($this->_formattedData))
        {
            if (isset($item['__parentId'])) {
                throw new JSONLFormatterException('First Item must be parent.');
            }
            if (!isset($item['id'])) {
                throw new JSONLFormatterException('id is missing in first item.');
            }
            else
            {
                $id_info = $this->getIdInfo($item['id']);

                $item_path = $this->preparePath($id_info['path']);

                if($item_path !== false) {
                    $this->setItemAtPath(explode('/', (string) $item_path), $item, $this->_formattedData);
                }
            }
        }
        else
        {
            if(!isset($item['id'])) {
                throw new JSONLFormatterException('id is missing in the item.');
            }
            $parent_path = '';
            if(isset($item['__parentId'])) {
                $parent_path = $this->getIdInfo($item['__parentId'])['path'];
            }

            // else {
            // 	$this->_paths = [];
            // }
            $id_info = $this->getIdInfo($item['id']);
            $item_path = $this->preparePath($id_info['path'], $parent_path);
            if($item_path !== false) {
                $this->setItemAtPath(explode('/', $item_path), $item, $this->_formattedData);
            }
        }
    }

    public function getFormattedData()
    {
        return $this->_formattedData;
    }

    private function preparePath($path, $parent_path='')
    {
        if(empty($parent_path)) {
            $prepared_path = $path;
        }
        else {
            if(isset($this->_paths[$parent_path])) {
                $prepared_path = $this->_paths[$parent_path] . '/' . $path;
            } else {
                // $this->_paths[$parent_path] = $parent_path;
                // $prepared_path = $this->_paths[$parent_path] . '/' . $path;
                return false;
            }
        }

        $this->_paths[$path] = $prepared_path;

        return $prepared_path;
    }

    /*private function setItemAtPath($path, $item, &$address)
    {
        if(count($path) !== 1) {
            $key = array_shift($path);
            $new_address = &$address[$key];
            $this->setItemAtPath($path, $item, $new_address);
        }
        else {
            $address[$path[0]] = $item;
            return true;
        }
    }*/

    private function setItemAtPath($path, $item, &$address)
    {
        $last_key = count($path)-1;

        foreach ($path as $key => $value) {
            if($key === $last_key) {
                $address[$value] = $item;
            }
            else {
                $address = &$address[$value];
            }
        }

        return true;
    }

    public function getIdInfo($id)
    {
        if(($pos = strpos((string) $id, '?')) !== false) {
            $id = substr((string) $id, 0, $pos);
        }

        $id = str_replace('gid://shopify/', '', $id);

        $id_info = explode('/', $id);

        return [
            'type'	=> $id_info[0],
            'id' 	=> $id_info[1],
            'path'	=> $id
        ];
    }
}