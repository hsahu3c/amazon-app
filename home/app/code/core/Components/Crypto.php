<?php

namespace App\Core\Components;

use \Aws\Kms\KmsClient;

/**
 * Component to handle encryption and decryption of data
 *
 * Note : An encryption key should already exist and its ARN should be placed
 * in config under the key "crypto_key_arn"
 */

class Crypto extends Base
{
    const DEFAULT_ASYMMETRIC = 'RSAES_OAEP_SHA_256';

    private  $client;
    private  $key;

    public function _construct()
    {
        $config = $this->di->getConfig()->path("aws.default");
        $this->client = new KmsClient($config->toArray());
        $this->key = $this->di->getConfig()->path("crypto_key_arn");
    }
    /**
     * Encrypt data using keys from kms
     *
     * @param string $text : text to encrypt
     * @param boolean $base64 : if true returned string will be base64 encoded
     *                          else it will be a CiphertextBlob
     * @return string
     */
    public function encrypt(string $text, $base64 = false)
    {
        $data = [
            'KeyId' => $this->key,
            'Plaintext' => $text,
        ];

        if ($this->getKeyType() !== 'SYMMETRIC_DEFAULT') {
            $data["EncryptionAlgorithm"] = self::DEFAULT_ASYMMETRIC;
        }
        $result = $this->client->encrypt($data);

        if ($base64) {
            return base64_encode($result['CiphertextBlob']);
        }
        return $result['CiphertextBlob'];
    }
    /**
     * Decrypt data using keys from kms
     *
     * @param string $cipherText : encrypted text
     * @param boolean $base64 : Should be true if the encrypted text is base64 encoded
     * @return string
     */
    public function decrypt(string $cipherText, $base64 = false)
    {
        if ($base64) {
            $cipherText = base64_decode($cipherText);
        }

        $data = [
            'KeyId' => $this->key,
            'CiphertextBlob' => $cipherText,
        ];

        if ($this->getKeyType() !== 'SYMMETRIC_DEFAULT') {
            $data["EncryptionAlgorithm"] = self::DEFAULT_ASYMMETRIC;
        }
        $result = $this->client->decrypt($data);
        return $result['Plaintext'];
    }
    /**
     * Get the key type [Symmetric or Asymmetric]
     *
     * @return string
     */
    public function getKeyType()
    {
        $result = $this->client->describeKey([
            'KeyId' => $this->key,
            "PolicyName" => "default"
        ]);
        return $result['KeyMetadata']['KeySpec'];
    }
}
