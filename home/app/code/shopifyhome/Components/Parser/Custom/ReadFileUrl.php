<?php
namespace App\Shopifyhome\Components\Parser\Custom;

use App\Core\Components\Base;
// use App\Shopifyhome\Components\Parser\ReadFile;
use App\Shopifyhome\Components\Parser\Exception\ReadFileException;

class ReadFileUrl extends Base
{
	public function __construct($file_path)
	{
        $file_path = urldecode((string) $file_path);
        $this->_fileInfo['filesize'] = $this->fileSize($file_path);
        $this->_fileInfo['filepath'] = $file_path;
		$this->_fileInfo['handle'] = fopen($file_path, "r") or die("Couldn't get handle");
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

	public function fileSize($file_path)
	{
		$pos = strpos($file_path, 'http');
		if($pos === false || $pos !== 0) {
			if(file_exists($file_path)) {
				return filesize($file_path);
			}
			else {
				throw new ReadFileException("Invalid file path");
			}
		}
		else {
			$url = $file_path;
			if (filter_var($url, FILTER_VALIDATE_URL)) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string
				curl_setopt($ch, CURLOPT_HEADER, true); // Include the header in the output
				curl_setopt($ch, CURLOPT_NOBODY, true); // Exclude the body from the output (HEAD request)
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Optional: Disable SSL certificate verification if needed for self-signed certificates or development environments. Use with caution in production.

				$response = curl_exec($ch);

				if ($response === false) {
					curl_close($ch);
				    throw new ReadFileException('cURL error: ' . curl_error($ch));
				} else {
				    $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
				    if ($size !== -1) { // -1 indicates Content-Length header was not found
				        curl_close($ch);
				        return $size;
				    } else {
				    	curl_close($ch);
				        throw new ReadFileException("Could not determine filesize (Content-Length header not found or server does not support HEAD requests for size)");
				    }
				}
			}
			else {
				throw new ReadFileException("Invalid file URL");
			}
		}
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