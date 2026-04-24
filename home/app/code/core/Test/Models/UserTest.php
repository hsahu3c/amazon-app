<?php
namespace App\Core\Test;
use App\Core\Components\UnitTestApp;
use App\Core\Models\User;

class UserTest extends UnitTestApp
{
    protected $di;
    public function testEncryptWithAESUsingPassphrase()
    {
        $data = "test data";
        $passphrase = "securepassphrase";

        $userModel = $this->di->getObjectManager()->get(User::class);

        $encryptedData = $userModel->encryptWithAES($data, $passphrase);
        $this->assertNotFalse($encryptedData, "Encryption failed");

        $decryptedData = $userModel->decryptWithAES($encryptedData, $passphrase);
        $this->assertEquals($data, $decryptedData, "Decryption failed with passphrase");
    }

    public function testEncryptWithAESUsingDerivedKey()
    {
        $data = "test data";
        $userModel = $this->di->getObjectManager()->get(User::class);
        $encryptedData = $userModel->encryptWithAES($data, null, 0);
        $this->assertNotFalse($encryptedData, "Encryption failed");

        $decryptedData = $userModel->decryptWithAES($encryptedData, null, 0);
        $this->assertEquals($data, $decryptedData, "Decryption failed with derived key");
    }

    public function testEncryptWithAESUsingPrivateKey()
    {
        $data = "test data";
        $userModel = $this->di->getObjectManager()->get(User::class);
        $encryptedData = $userModel->encryptWithAES($data);
        $this->assertNotFalse($encryptedData, "Encryption failed");

        $decryptedData = $userModel->decryptWithAES($encryptedData);
        $this->assertEquals($data, $decryptedData, "Decryption failed with private key");
    }
}
