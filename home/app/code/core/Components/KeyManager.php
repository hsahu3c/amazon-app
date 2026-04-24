<?php

namespace App\Core\Components;

use App\Core\Models\BaseMongo;
use Aws\Kms\KmsClient;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use MongoDB\BSON\Binary;
use MongoDB\Collection;

class KeyManager extends Base
{
    private $dir = BP . DS . 'app' . DS . 'etc' . DS . 'security' . DS;
    private $pemFile = 'connector.pem';
    private $pubFile = 'connector.pub';
    private $collection;
    public function getKeyCollection(): Collection
    {
        $mongo = $this->di->getObjectManager()->get(BaseMongo::class);
        if (!$this->collection) {
            $this->collection = $mongo->getCollection('crypto_keys');
        }
        return $this->collection;
    }
    public function getKeyArn(): ?string
    {
        if (
            $this->di->getConfig()->path('xiferp_retemarap_swa', null)
            && ($this->di->getConfig()->path('jwt', null))
        ) {
            return $this->di->getConfig()->path('jwt', null)->toArray()['arn'];
        }
        if ($this->getKeyCollection()->findOne(["type" => 'jwt'])) { // from database
            return $this->getKeyCollection()->findOne(["type" => 'jwt'])['arn'];
        }
        return null;
    }

    public function getPrivateKey(): string
    {
        $keyData = [];
        $textKey = null;
        $currentApp = $this->di->getAppCode()->getCurrentApp();
        if ($currentApp === null && file_exists($this->dir . $this->pemFile)) { // From pem file
            return file_get_contents($this->dir . $this->pemFile);
        } elseif (
            $this->di->getConfig()->path('xiferp_retemarap_swa', null)
        ) { // From parameter store (config)
            if ($currentApp != null) {
                if ($this->di->getConfig()->get("securityFiles") && $this->di->getConfig()->get("securityFiles")[$currentApp]) {
                    return $this->di->getConfig()->get("securityFiles")[$currentApp]['pemFile'];
                } else {
                    return file_get_contents($this->dir . $this->pemFile);
                }
            } elseif ($this->di->getConfig()->path('jwt', null)) {
                $keyData = base64_decode($this->di->getConfig()->path('jwt', null)->toArray()['private']);
            }
        } elseif ($this->getKeyCollection()->findOne(["type" => 'jwt'])) { // from database
            $keyData = $this->getKeyCollection()->findOne(["type" => 'jwt'])['private'];
        } else { // Create new key
            $kms = new KmsClient($this->di->getConfig()->path("aws.default")->toArray());
            $sts = new StsClient($this->di->getConfig()->path('aws.default')->toArray());
            $arn = '';
            $userArn = $sts->getCallerIdentity()['Arn'];
            $policy = [
                'Version' => '2012-10-17',
                'Id' => 'key-policy',
                'Statement' => [
                    [
                        'Sid' => 'Allow full access to key creator',
                        'Effect' => 'Allow',
                        'Principal' => ['AWS' => $userArn],
                        'Action' => 'kms:*',
                        'Resource' => '*'
                    ]
                ]
            ];
            $result = $kms->createKey([
                "Policy" => json_encode($policy),
                "Description" => 'Key used to sign jwt tokens'
            ]);
            $arn = $result->get('KeyMetadata')['Arn'];
            $dataKey = $kms->generateDataKeyPair([
                "KeyId" => $arn,
                'KeyPairSpec' => 'RSA_2048',
            ]);
            $this->saveKey($dataKey['PrivateKeyCiphertextBlob'], $dataKey['PublicKey'], $arn);
            $textKey = $dataKey['PrivateKeyPlaintext'];
        }
        $kms = new KmsClient($this->di->getConfig()->path("aws.default")->toArray());
        if (!isset($textKey)) {
            $textKey = $kms->decrypt(
                [
                    'CiphertextBlob' => $keyData,
                ]
            );
            $textKey = $textKey['Plaintext'];
        }
        $textKey = base64_encode($textKey);
        $textKey = chunk_split($textKey, 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n" . $textKey . "-----END PRIVATE KEY-----\n";
    }

    public function saveKey(string $private, string $public, string $arn)
    {
        if ($paramPrefix = $this->di->getConfig()->path('xiferp_retemarap_swa', null)) {
            $paramStore = new SsmClient($this->di->getConfig()->path("aws.default")->toArray());
            $paramStore->putParameter([
                "Name" => "/$paramPrefix/jwt/private",
                "Value" => base64_encode($private),
                "Type" => "String",
                "Overwrite" => true,
            ]);
            $paramStore->putParameter([
                "Name" => "/$paramPrefix/jwt/public",
                "Value" => base64_encode($public),
                "Type" => "String",
                "Overwrite" => true,
            ]);
            $paramStore->putParameter([
                "Name" => "/$paramPrefix/jwt/arn",
                "Value" => $arn,
                "Type" => "String",
                "Overwrite" => true,
            ]);
            $this->di->getCache()->flush();
        } else {
            $this->getKeyCollection()->updateOne(
                ['type' => 'jwt'],
                [
                    '$set' => [
                        "arn" => $arn,
                        "private" => new Binary($private, Binary::TYPE_GENERIC),
                        "public" => new Binary($public, Binary::TYPE_GENERIC),
                    ]
                ],
                ['upsert' => true]
            );
        }
    }

    public function getPublicKey(): string
    {
        if (file_exists($this->dir . $this->pubFile)) { // From pem file
            return file_get_contents($this->dir . $this->pubFile);
        } elseif (
            $this->di->getConfig()->path('xiferp_retemarap_swa', null)
        ) { // From parameter store (config)
            $currentApp = $this->di->getAppCode()->getCurrentApp();
            if ($currentApp != null) {
                if ($this->di->getConfig()->get("securityFiles") && $this->di->getConfig()->get("securityFiles")[$currentApp]) {
                    return $this->di->getConfig()->get("securityFiles")[$currentApp]['pubFile'];
                } else {
                    return file_get_contents($this->dir . $this->pubFile);
                }
            } elseif ($this->di->getConfig()->path('jwt', null)) {
                $keyData = base64_decode($this->di->getConfig()->path('jwt', null)->toArray()['public']);
            }
        } elseif ($this->getKeyCollection()->findOne(["type" => 'jwt'])) { // from database
            $keyData = $this->getKeyCollection()->findOne(["type" => 'jwt'])['public'];
        } else {
            throw new \Exception("Public key not found, Please create a new key pair");
        }
        $textKey = chunk_split(base64_encode($keyData), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $textKey . "-----END PUBLIC KEY-----\n";
    }
}
