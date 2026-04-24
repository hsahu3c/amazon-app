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

use App\Core\Components\Base;
use App\Shopifyhome\Components\Parser\Exception\ReadFileException;

class ReadFile extends Base
{
	private $_fileInfo = [];

	public function __construct($file_path)
	{
        $file_path = urldecode((string) $file_path);
        if(file_exists($file_path)) {
            $this->_fileInfo['filepath'] = $file_path;
			$this->_fileInfo['filesize'] = filesize($file_path);
			$this->_fileInfo['handle'] = fopen($file_path, "r") or die("Couldn't get handle");
		}
		else {
			throw new ReadFileException("Invalid file path");
		}
	}

	public function __destruct() {
		fclose($this->getFileHandle());
    }

	public function getFileSize()
	{
		if(isset($this->_fileInfo['filesize'])) {
			return $this->_fileInfo['filesize'];
		}
  throw new ReadFileException("constructor is not initialised.");
	}

	public function getFilePath()
	{
		if(isset($this->_fileInfo['filepath'])) {
			return $this->_fileInfo['filepath'];
		}
  throw new ReadFileException("constructor is not initialised.");
	}

	public function getFileHandle()
	{
		if(isset($this->_fileInfo['handle'])) {
			return $this->_fileInfo['handle'];
		}
  throw new ReadFileException("constructor is not initialised.");
	}

	public function getJsonLines($params)
	{
		// $handle = fopen($this->getFilePath(), "r") or die("Couldn't get handle");
		$handle = $this->getFileHandle();

		if($handle)
		{
			if(isset($params['seek'])) {
				fseek($handle, $params['seek']);
			}
		    
		    $i = 1;
		    $number_of_items = $params['rows'] ?? 300;

		    $jsonLines = [];

		    while(!feof($handle))
		    {
		    	if($i <= $number_of_items) {
		    		$row_data = fgets($handle);
		    		if($row_data) {
		    			$jsonLines[] = trim($row_data);
		    		}
		    	}
		    	else {
		    		break;
		    	}

		    	$i++;
		    }

		    $response['seek'] = ftell($handle);
		    
		    // fclose($handle);

		    $response['data'] = '[' . implode(',', $jsonLines) . ']';

		    return $response;
		}
	}
}