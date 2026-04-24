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

namespace App\Shopifyhome\Components\Parser\Custom;

use App\Shopifyhome\Components\Parser\JSONLFormatter;
use App\Shopifyhome\Components\Parser\Custom\ReadFileUrl;

class JSONLParser extends ReadFileUrl
{
    public const DELIMETER = '~';

    public function getItemCount()
    {
        $counts = [];

        $jsonl_formatter = new JSONLFormatter();

        $file_cursor_position = 0;
        while ($file_cursor_position < $this->getFileSize())
        {
            $json_lines_data = $this->getJsonLines(['seek' => $file_cursor_position]);

            $json = $json_lines_data['data'];
            $items = json_decode((string) $json, true);

            foreach ($items as $item)
            {
                if(isset($item['id']))
                {
                    $id_info = $jsonl_formatter->getIdInfo($item['id']);
                    if(isset($counts[$id_info['type']])) {
                        $counts[$id_info['type']]++;
                    } else {
                        $counts[$id_info['type']] = 1;
                    }
                }
            }

            $file_cursor_position = $json_lines_data['seek'];
        }

        return [
            'success'	=> true,
            'data'		=> $counts
        ];
    }

    public function getItems($params)
    {
        $limit = isset($params['limit']) ? intval($params['limit']) : 250;
        $file_cursor = 0;
        $skip_till_key = -1;

        if(isset($params['next']))
        {
            $validate = $this->validateNext($params['next']);
            if($validate['status'])
            {
                $explod = explode(self::DELIMETER, (string) $validate['data']);
                array_walk($explod, function (&$item) {$item = intval($item);});
                [$limit, $file_cursor, $skip_till_key] = $explod;
            }
            else
            {
                return [
                    'success'	=> false,
                    'msg'       => 'Next Cursor is Invalid'
                ];
            }
        }

        $count = 0;
        $file_cursor_prev_position = $file_cursor_current_position = $file_cursor;

        $flag_breaked = $cursor_locked = false;

        $skip_till_key_return = -1;

        $jsonl_formatter = new JSONLFormatter();

        while ($file_cursor < $this->getFileSize())
        {
            $json_lines_data = $this->getJsonLines(['seek' => $file_cursor]);

            $json = $json_lines_data['data'];
            $items = json_decode((string) $json, true);

            foreach ($items as $key => $item)
            {
                /**
                 * if $skip_till_key is 17 then we'll skip till key 16. From 17 we'll start processing
                 */
                if($skip_till_key > $key) {
                    continue;
                }

                $skip_till_key = -1;

                if(isset($item['__parentId']))
                {
                    $jsonl_formatter->setItem($item);
                }
                else
                {
                    if(!$flag_breaked)
                    {
                        if($count === $limit) {
                            $flag_breaked = true;
                            $skip_till_key_return = $key;
                            // break;
                        }
                        else {
                            $jsonl_formatter->setItem($item);
                            $count++;
                        }
                    }
                }
            }

            if(!$cursor_locked) {
                $file_cursor_prev_position = $file_cursor;
                $file_cursor_current_position = $json_lines_data['seek'];
            }

            if($flag_breaked) {
                $cursor_locked = true;
            }

            $file_cursor = $json_lines_data['seek'];
        }

        $response = [
            'success'	=> true,
            'data'		=> $jsonl_formatter->getFormattedData()
        ];

        if($flag_breaked) {
            $response['cursors']['next'] = urlencode(base64_encode($limit . self::DELIMETER . $file_cursor_prev_position . self::DELIMETER . $skip_till_key_return));
        }
        else {
            if($file_cursor_current_position !== 0 && $file_cursor_current_position !== $this->getFileSize()) {
                $response['cursors']['next'] = urlencode(base64_encode($limit . self::DELIMETER . $file_cursor_current_position . self::DELIMETER . '-1'));
            }
        }

        return $response;
    }


    public function validateNext($next)
    {
        $next = urldecode((string) $next);
        $next = base64_decode($next, true);

        if($next) {
            if(substr_count($next, self::DELIMETER) === 2) {
                return [
                    'status'=> true,
                    'data'	=> $next
                ];
            }
        }

        return ['status' => false];
    }
}