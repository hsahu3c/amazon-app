<?php

namespace App\Connector\Components;

class PIIDataManager extends \App\Core\Components\Base
{

    protected $encryptionKey;
    protected $iv;
    protected $method;
    protected $keysToEncryptDecrypt;

    /**
     * Fetch the encryption key from config and initialising variables
     */
    public function _construct()
    {
        try {
            $encryptionConfig = $this->di->getConfig()->get('pii_data_encryption_manager');
            if (!empty($encryptionConfig) && isset($encryptionConfig['encryption_key'], $encryptionConfig['iv'],$encryptionConfig['method'],$encryptionConfig['keys_to_encrypt'])) {
                $this->encryptionKey = base64_decode($encryptionConfig['encryption_key']);
                $this->iv = str_pad($encryptionConfig['iv'],16,'c');
                $this->method = $encryptionConfig['method'];
                $this->keysToEncryptDecrypt = $encryptionConfig['keys_to_encrypt'];
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }
    /**
     * Encrypt a value using provided method
     *
     * @param string $value
     * @return void
     */
    private function encryptValue($value) {
        return openssl_encrypt(
            $value,
            $this->method,
            $this->encryptionKey,
            0,
            $this->iv
        );
    }

    /**
     * Decrypt a value using provided method
     *
     * @param string $encryptedValue
     * @return void
     */ 
    private function decryptValue($encryptedValue) {
        return openssl_decrypt(
            $encryptedValue,
            $this->method,
            $this->encryptionKey,
            0,
            $this->iv
        );
    }

    /**
     * Encrypt values at specific paths in the multidimensional array
     *
     * @param array $array
     * @param array $paths
     * @return void
     */
    public function encryptByPaths(&$array, $paths) {
        foreach ($paths as $path) {
            $this->encryptByPath($array, $path);
        }
    }

    /**
     * Decrypt values at specific paths in the multidimensional array
     *
     * @param array $array
     * @param array $paths
     * @return void
     */
    public function decryptByPaths(&$array, $paths) {
        foreach ($paths as $path) {
            $this->decryptByPath($array, $path);
        }
    }

    /**
     * Encrypt a value at a specific path in the multidimensional array
     *
     * @param array $array
     * @param string $path
     * @return void
     */
    public function encryptByPath(&$array, $path) {
        $keys = explode('.', $path);
        $current = &$array;
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                throw new \Exception("Invalid array index path: $path");
            }
            $current = &$current[$key];
        }
        if (is_array($current)) {
            throw new \Exception("Path should be leaf node or have string value: $path");
        }
        $current = $this->encryptValue($current);
    }

    /**
     * Decrypt a value at a specific path in the multidimensional array
     *
     * @param array $array
     * @param string $path
     * @return void
     */
    public function decryptByPath(&$array, $path) {
        $keys = explode('.', $path);
        $current = &$array;
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                throw new \Exception("Invalid array index path: $path");
            }
            $current = &$current[$key];
        }
        if (is_array($current)) {
            throw new \Exception("Path should be leaf node or have string value: $path");
        }
        $current = $this->decryptValue($current);
    }

    /**
     * Encrypt or Decrypt data.
     *
     * @param array $data array containing the data.
     * @param array $paths array containing the paths to encrypt of decrypt.
     * @param array $encrypt boolean to decide whether we have to encrypt or decript.
     * @return void
     */
    public function encryptDecrypt(&$data, $paths = [], $encrypt = true)
    {
        if (empty($paths)) {
            $paths = $this->keysToEncryptDecrypt;
        }
        $function = $encrypt ? 'encryptByPaths' : 'decryptByPaths';
        $this->$function($data, $paths);
    }
}