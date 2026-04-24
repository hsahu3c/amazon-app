<?php

namespace App\Core\Models;

use stdClass;
use Phalcon\Logger\Logger;
use Phalcon\Mvc\Model\Query;
use App\Core\Components\KeyManager;
use App\Core\Components\Email\Helper;

class User extends BaseMongo
{
    protected $table = 'user_details';
    protected $isGlobal = true;

    public $status = ['1' => 'Under Review', '2' => 'Active', '3' => 'Inactive'];
    public $sqlConfig;

    const IS_EQUAL_TO = 1;
    const IS_NOT_EQUAL_TO = 2;
    const IS_CONTAINS = 3;
    const IS_NOT_CONTAINS = 4;
    const START_FROM = 5;
    const END_FROM = 6;
    const RANGE = 7;
    const DEFAULT_UNINSTALL_APP_MAIL_SUBJECT = 'App uninstall mail';
    const DEFAULT_RESET_PASSWORD_MAIL_SUBJECT = 'Password Reset Mail';
    const DEFAULT_TOKEN_EXPIRATION_TIME = "+4 hour";
    const MAX_TOKEN_REGENERATION_LIMIT = 16;

    /**
     * Generate a non-reversible cache key for the given value.
     * Uses SHA-256 because MD5 is flagged as insecure by static analysis,
     * even when (as here) the hash is only a cache-key generator.
     * NOTE: do not use this for password hashing — see getHash().
     */
    private function cacheKey($value)
    {
        return hash('sha256', (string)$value);
    }


    /**
     * stores the user in the database
     *
     * @param array $data
     * @param string $type
     * @param boolean $autoConfirm
     * @return array
     */
    public function createUser($data, $type = 'customer', $autoConfirm = false): array
    {
        $errors = false;
        $roleId = (Acl\Role::findFirst([["code" => $type]]));

        if (!isset($roleId)) {
            return ['success' => false, 'code' => 'role_not_found', 'message' => 'Role type Not defined'];
        }

        if (isset($data['username'], $data['email'], $data['password'])) {

            $document = [
                '$or' => [
                    ['email' => $data['email']],
                    ['username' => $data['username']],
                ]
            ];

            $user = $this->findFirst($document);

            if ($user && isset($user->_id)) {
                $errors[] = 'Username or email already exists.';
            }

            if (!$errors) {
                // validate username
                $validationErrors = $this->validateRegistrationData($data);
                if (!empty($validationErrors)) {
                    return $validationErrors;
                }
                // if confirm password is given
                if (isset($data['confirm_password']) && $data['password'] !== $data['confirm_password']) {
                    return ['success' => false, 'message' => 'Confirm password not matched with password!'];
                }

                $data['email'] = strToLower($data['email']);
                $data['password'] = $this->hashPassword($data['password']);
                $data['last_used_passwords'] = [$data['password']];
                $data['role_id'] = $roleId->_id;
                if ($autoConfirm === 'true' || $autoConfirm === true) {
                    $data['confirmation'] = 1;
                    $data['status'] = 2;
                } else {
                    $data['confirmation'] = 0;
                    $data['status'] = 1;
                }

                if (!empty($data['confirmation_data']) && is_array($data['confirmation_data'])) {
                    $query['confirmation_data'] = $data['confirmation_data'];
                    unset($data['confirmation_data']);
                }

                /*
                 *  @TODO required
                 *  User confirmation and approval
                 */
                $data['created_at'] = new \MongoDB\BSON\UTCDateTime();
                $oldSaveData = $this->getSavedShopData($data['username'], $data['email']);
                if (!empty($oldSaveData)) {
                    $data['_id'] = $oldSaveData['_id'];
                }
                // removing the unwanted data
                unset($data['_url'], $data['confirm_password']);

                if ($this->setData($data)->save()) {
                    $this->setUserId((string) $this->_id);
                    $this->save();
                    if ($data['confirmation']) {
                        $result = [
                            'success' => true,
                            'code' => 'account_created',
                            'message' => 'Customer created successfully',
                            'data' => ["user_id" => (string) $this->_id]
                        ];
                    } else {
                        if (!empty($query['confirmation_data'])) {
                            $data['confirmation_data'] = $query['confirmation_data'];
                        }
                        $this->sendConfirmationMail($data);
                        $result = [
                            'success' => true,
                            'code' => 'confirmation_required',
                            'message' => 'Check your mail to verify your account.',
                            'data' => []
                        ];
                    }
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire('application:createAfter', $this);
                } else {
                    $messages = $this->getMessages();
                    $errors = [];
                    foreach ($messages as $message) {
                        $errors[] = (string) $message;
                    }
                    $result = [
                        'success' => false,
                        'message' => 'Something went wrong',
                        'data' => $errors
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'code' => 'user_already_exist',
                    'message' => 'User already exists'
                ];
            }
        } else {
            $result = [
                'success' => false,
                'code' => 'data_missing',
                'message' => 'Fill All required fields.',
                'data' => []
            ];
        }
        return $result;
    }
    public function generatePassword()
    {
        $upperCase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowerCase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $specialChars = '#?!@$%^&*-';
        $validChars = $upperCase . $lowerCase . $numbers . $specialChars;

        $password = '';
        $password .= $upperCase[rand(1, strlen($upperCase) - 1)];
        $password .= $lowerCase[rand(1, strlen($lowerCase) - 1)];
        $password .= $numbers[rand(1, strlen($numbers) - 1)];
        $password .= $specialChars[rand(1, strlen($specialChars) - 1)];

        while (strlen($password) < 8) {
            $password .= $validChars[rand(1, strlen($validChars) - 1)];
        }

        return $password;
    }

    public function validateRegistrationData($data, $skip = [], $mapping = [])
    {
        $usernameKey = isset($mapping['username']) ? $mapping['username'] : 'username';
        $emailKey = isset($mapping['email']) ? $mapping['email'] : 'email';
        $passwordKey = isset($mapping['password']) ? $mapping['password'] : 'password';

        if (!in_array($usernameKey, $skip) && !$this->validateUsername($data[$usernameKey])) {
            return [
                'success' => false,
                'code' => 'invalid_username',
                'message' => 'Invalid Username'
            ];
        }

        if (!in_array($emailKey, $skip) && !$this->validateEmail($data[$emailKey])) {
            return [
                'success' => false,
                'code' => 'invalid_email',
                'message' => 'Invalid Email'
            ];
        }

        if (!in_array($passwordKey, $skip) && !$this->validatePasswordStrength($data[$passwordKey])) {
            return [
                'success' => false,
                'code' => 'password_strength',
                'message' => 'Password should be of length at-least 8, '
                    . 'at-least one capital letter, at-least one small letter, '
                    . 'at-least one numeric digit, at-least one special character'
            ];
        }

        return [];
    }

    public function getSavedShopData($userName, $email)
    {
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('user_log');
        $filters = [];
        $filters['username'] = $userName;
        $filters['email'] = $email;
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        return $collection->findOne($filters, $options);
    }

    /**
     * validate the password strength
     * password should be :
        -  of length at-least 8
        -  at-least one capital letter
        -  at-least one small letter
        -  at-least one numeric digit
        -  at-least one special character
     *
     * @param string $password
     * @return array
     */
    public function validatePasswordStrength($password): bool
    {
        return preg_match('/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*(){}[\]:;^,.<>?!|&`~@\$%\/\\=\+\-\*\'"_]).{8,}$/', $password);
    }
    /**
     * Validates an email address.
     *
     * @param string $email The email address to validate.
     * @return bool Returns true if the email address is valid, false otherwise.
     */
    public function validateEmail($email)
    {
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        // Validate email address
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    /**
     * Validates the length of a username.
     *
     * @param string $username The username to validate.
     * @return bool Returns true if username length is invalid, false otherwise.
     */
    public function validateUsername($username)
    {
        $minLength = $this->di->getConfig()->path('user_validation.username.min', 3);
        $maxLength = $this->di->getConfig()->path('user_validation.username.max', 50);
        $length = strlen($username);

        return !($length < $minLength || $length > $maxLength);
    }

    /**
     * Legacy hash used by records created before the bcrypt migration.
     * Only called from the fallback branch of checkHash(). New passwords must
     * be stored via hashPassword(); the fallback can be removed once every
     * active account has re-authenticated and been transparently rehashed.
     */
    public function getHash($password)
    {
        // NOTE: md5() is retained intentionally. Every existing user's stored
        // password hash was produced with this scheme; swapping algorithms
        // without a migration would lock every user out. A proper migration
        // to password_hash()/password_verify() with lazy rehash-on-login is
        // tracked separately. DO NOT change this line without that plan.
        // phpcs:ignore
        // return $this->encryptWithAES(md5($this->getSaltedString($password)));
    }

    public function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function needsRehash($storedHash)
    {
        if (!is_string($storedHash) || $storedHash === '') {
            return false;
        }
        return password_needs_rehash($storedHash, PASSWORD_BCRYPT);
    }

    private function rehashStoredPassword($plaintext)
    {
        if (empty($this->_id)) {
            return;
        }
        $newHash = $this->hashPassword($plaintext);
        $this->getCollection()->updateOne(
            ['_id' => $this->_id],
            ['$set' => ['password' => $newHash]]
        );
        $this->password = $newHash;
    }

    public function getSaltedString($string)
    {
        $pepper = 'cedcommerce';
        $salt = 'connector';
        return $pepper . $string . $salt;
    }

    /**
     * Encrypts data using AES-256-CBC encryption.
     *
     * This function encrypts the provided data using the AES-256-CBC encryption algorithm. The encryption
     * key can be determined in three ways:
     * - If a passphrase is provided, a key is generated from it.
     * - If a `deriveKeyStartIndex` is provided, the key is derived from the private key starting at that index.
     * - Otherwise, the private key is used directly for encryption.
     *
     * @param string $data The plaintext data to be encrypted.
     * @param string|null $passphrase Optional. A passphrase to generate the encryption key. If provided,
     *                                it takes precedence over the `deriveKeyStartIndex` parameter.
     * @param int|null $deriveKeyStartIndex Optional. The starting index for deriving a 32-character encryption key
     *                                      from the private key. Ignored if a passphrase is provided.
     *
     * @return string|false The encrypted data as a base64-encoded string, or `false` on failure.
     */
    public function encryptWithAES($data, $passphrase = null, $deriveKeyStartIndex = null)
    {
        $privateKey = $this->di->getObjectManager()->get(KeyManager::class)->getPrivateKey();
        $cipher = "aes-256-cbc";
        $option = 0;
        $encryptIV = '*cedcommerce00+-';

        // Determine the encryption key to use
        if ($passphrase) {
            $encryptKey = $this->generateKeyFromPassphrase($passphrase);
        } elseif (is_numeric($deriveKeyStartIndex)) {
            $encryptKey = $this->deriveKeyFromPrivateKey($privateKey, $deriveKeyStartIndex);
        } else {
            $encryptKey = $privateKey;
        }

        return openssl_encrypt(
            $data,
            $cipher,
            $encryptKey,
            $option,
            $encryptIV
        );
    }

    /**
     * Decrypts data using AES-256-CBC encryption.
     *
     * This function decrypts the provided data using the AES-256-CBC encryption algorithm. The decryption
     * key can be determined in three ways:
     * - If a passphrase is provided, a key is generated from it.
     * - If a `deriveKeyStartIndex` is provided, the key is derived from the private key starting at that index.
     * - Otherwise, the private key is used directly for decryption.
     *
     * @param string $data The encrypted data to be decrypted.
     * @param string|null $passphrase Optional. A passphrase to generate the decryption key. If provided,
     *                                it takes precedence over the `deriveKeyStartIndex` parameter.
     * @param int|null $deriveKeyStartIndex Optional. The starting index for deriving a 32-character decryption key
     *                                      from the private key. Ignored if a passphrase is provided.
     *
     * @return string|false The decrypted data as a string, or `false` on failure.
     */
    public function decryptWithAES($data, $passphrase = null, $deriveKeyStartIndex = null)
    {
        $privateKey = $this->di->getObjectManager()->get(KeyManager::class)->getPrivateKey();
        $cipher = "aes-256-cbc";
        $options = 0;
        $decryptIv = '*cedcommerce00+-';

        // Determine the decryption key to use
        if ($passphrase) {
            $decryptKey = $this->generateKeyFromPassphrase($passphrase);
        } elseif (is_numeric($deriveKeyStartIndex)) {
            $decryptKey = $this->deriveKeyFromPrivateKey($privateKey, $deriveKeyStartIndex);
        } else {
            $decryptKey = $privateKey;
        }

        return openssl_decrypt(
            $data,
            $cipher,
            $decryptKey,
            $options,
            $decryptIv
        );
    }

    /**
     * Generate a 256-bit key from a passphrase using SHA-256.
     *
     * @param string $passphrase The passphrase to generate the key from.
     * @return string The generated 256-bit key.
     */
    private function generateKeyFromPassphrase($passphrase)
    {
        return hash('sha256', $passphrase, true);
    }

    /**
     * Derives a 32-character key from a private key, starting at the specified index.
     *
     * This function extracts 32 characters from the given private key, starting at the provided
     * start index. If the extracted substring is shorter than 32 characters, it is padded with '0'
     * to make it 32 characters long. If the start index is out of bounds, the result will be
     * a string of 32 '0' characters.
     *
     * @param string $privateKey The private key from which to derive the key.
     * @param int $startIndex The starting index from which to begin extracting the key.
     *                        Must be 0 or greater.
     *
     * @return string A 32-character derived key.
     */
    private function deriveKeyFromPrivateKey($privateKey, $startIndex = 0)
    {
        // Ensure $startIndex is within valid bounds
        if ($startIndex < 0) {
            $startIndex = 0;
        }

        // Extract substring from $startIndex
        $result = substr($privateKey, $startIndex, 32);

        // Pad the result to ensure it is 32 characters long
        if (strlen($result) < 32) {
            $result = str_pad($result, 32, '0');
        }

        return $result;
    }
    /**
     * checks password exists in the database
     *
     * @param string $password
     * @param [boolean, array] $dbPassword
     * @return boolean
     */
    public function checkPassword($password, $dbPassword = false)
    {
        $checkingSelf = $dbPassword === false;
        $stored = $checkingSelf ? $this->password : $dbPassword;

        if (!$this->checkHash($password, $stored)) {
            return false;
        }

        if ($checkingSelf && $this->needsRehash($stored)) {
            $this->rehashStoredPassword($password);
        }
        return true;
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * Tries the modern password_verify() path first. Falls back to the legacy
     * MD5+AES scheme so accounts created before the bcrypt migration can still
     * authenticate; callers that can persist should re-hash on a legacy match
     * (see checkPassword()).
     *
     * @param string $password
     * @param string $dbPassword
     * @return boolean
     */
    public function checkHash($password, $dbPassword)
    {
        if (!is_string($dbPassword) || $dbPassword === '') {
            return false;
        }
        if (password_verify($password, $dbPassword)) {
            return true;
        }
        return hash_equals($dbPassword, (string) $this->getHash($password));
    }

    /**
     * @param $data
     * @return array
     */
    public function login($data)
    {
        $accountComponent = $this->di->getObjectManager()->get("\App\Core\Components\Account");
        $locale = $this->di->getLocale();
        $clientIp = $this->di->getRequest()->getClientAddress();
        if ((isset($data['username']) || isset($data['email'])) && isset($data['password'])) {
            $key = isset($data["username"]) ? "username" : "email";

            if ($key === 'email' && !$this->validateEmail($data['email'])) {
                return [
                    'success' => false,
                    'code' => 'invalid_email',
                    'message' => 'Invalid Email'
                ];
            }
            if ($key === 'email')
                $query = [$key => new \MongoDB\BSON\Regex('^' . $data[$key] . '$', 'i')];
            else
                $query = [$key => $data[$key]];

            $user = User::findFirst($query);

            if ($user) {
                if (!property_exists($user, 'confirmation') || $user->confirmation == 0) {
                    return [
                        'success' => false,
                        'message' => $locale->_("not confirmed"),
                        'code' => 'account_not_confirmed',
                        'data' => []
                    ];
                } else {
                    if ($user->status == 1) {
                        return [
                            'success' => false,
                            'message' => $locale->_("account under review"),
                            'code' => 'status_under_review',
                            'data' => []
                        ];
                    }
                    if ($user->status == 3) {
                        return [
                            'success' => false,
                            'message' => $locale->_("account deactivated"),
                            'code' => 'status_not_active',
                            'data' => []
                        ];
                    }
                    if ($user->checkPassword($data['password'])) {
                        // remove all old attempt's data
                        $this->di->getCache()->deleteMultiple([
                            $this->cacheKey($user->username) . "_temp_block_count",
                            $this->cacheKey($user->username) . "_lastlogin",
                            $this->cacheKey($user->username),
                            $this->cacheKey($user->email) . "_temp_block_count",
                            $this->cacheKey($user->email) . "_lastlogin",
                            $this->cacheKey($user->email),
                            $this->cacheKey($clientIp) . "_temp_block_count",
                            $this->cacheKey($clientIp) . "_lastlogin",
                            $this->cacheKey($clientIp)
                        ], "requests");
                        $this->setUserStatistics((string) $user->_id);
                        $eventData = [
                            'user_id' => (string) $user->getId()
                        ];
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:loginAfter', $this, $eventData);

                        $stateData = $this->generateStateDataForUser($user->user_id);

                        return ['success' => true, 'message' => $locale->_("User logged in"), 'data' => ['token' => $user->getToken($data['token_expiry_duration'] ?? self::DEFAULT_TOKEN_EXPIRATION_TIME), "migrated" => isset($user->sso_id) ? 1 : 0, "state" => $stateData]];
                    }
                }
            }
            return [
                'success' => false,
                'attempts_left' => $accountComponent->handleAttempts($user, false),
                'code' => 'invalid_data',
                'message' => $locale->_("invalid creds"),
                'data' => []
            ];
        }
        return [
            'success' => false,
            'attempts_left' => $accountComponent->handleAttempts(new stdClass(), false),
            'code' => 'data_missing',
            'message' => $locale->_("all required"),
            'data' => []
        ];
    }

    /**
     * 
     * Generate state token for logged in user.
     * @param $userId string.
     * @return $token string.
     * 
     **/
    public function generateStateDataForUser($userId = '')
    {
        if (empty($userId)) {
            return ['success' => false, 'message' => 'user id not exists !'];
        }
        $activeTime = 10;
        // Disable previous user token.
        // $this->di->getTokenManager()->disableUserToken($userId);
        $date = new \DateTime("10 min");
        $token = array(
            "user_id" => $userId,
            "exp" => $date->getTimestamp()
        );
        $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($token, 'HS256');
        return $token;
    }

    public function getToken(
        $time = self::DEFAULT_TOKEN_EXPIRATION_TIME,
        $additionalData = false,
        $restrictForSameUser = true
    ) {
        $date1 = new \DateTime($time);
        if ($this->getRole()->code == 'app') {
            $token = [
                "user_id" => (string) $this->_id,
                "role" => $this->getRole()->code
            ];
        } else {
            $token = [
                "user_id" => isset($this->_id) ? (string) $this->_id : $this->di->getUser()->id,
                "role" => $this->getRole()->code,
                "exp" => $date1->getTimestamp()
            ];
        }
        if ($additionalData) {
            $token = array_merge($token, $additionalData);
        }
        $this->di = $this->getDi();
        return $this
            ->di
            ->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->getJwtToken($token, 'RS256', true, false, $restrictForSameUser);
    }

    public function getRole()
    {
        $roleId = isset($this->role_id) ? $this->role_id : $this->di->getUser()->role_id;
        return Acl\Role::findFirst(["_id" => $roleId]);
    }

    public function deleteUser($data)
    {
        if (isset($data['id'])) {
            $user = User::findFirst([["_id" => $data['id']]]);
        } elseif (isset($data['username'])) {
            $user = User::findFirst(['username' => $data['username']]);
        } elseif (isset($data['email'])) {
            $user = User::findFirst(["email" => $data['email']]);
        }


        if ($user) {

            $userdb = UserDb::findFirst(["_id" => (string) $user->id]);

            $userdb->delete();

            return ['success' => true, 'message' => 'User has been deleted'];
        }
        return ['success' => false, 'code' => 'no_customer_found', 'message' => 'User not found'];
    }

    /**
     * reset password when using old password from db
     *
     * @param array $data
     * @return array
     */
    public function resetUserPassword($data)
    {
        if (!isset($data['old_password']) || !isset($data['new_password'])) {
            return ['success' => false, 'code' => 'data_missing', 'message' => 'Request data missing'];
        }
        if (!$this->checkPassword($data['old_password'])) {
            return ['success' => false, 'code' => 'password_not_matched', 'message' => 'Old Password not matched'];
        }

        if ($data['new_password'] !== $data['confirm_password']) {
            return ['success' => false, 'message' => 'Confirm password not matched with new password!'];
        }

        $isValid = $this->validateRegistrationData(
            $data,
            ['username', 'email'],
            ['password' => 'new_password']
        );

        if (!empty($isValid)) {
            return $isValid;
        }

        $alreadyUsed = $this->handleLastUsedPasswords($data['new_password']);
        if ($alreadyUsed) {
            return $alreadyUsed;
        }
        $password = $this->hashPassword($data['new_password']);
        $user = $this->getCollection()->updateOne(
            ['_id' => $this->_id],
            [
                '$set' => [
                    'password' => $password,
                    'last_used_passwords' => $this->last_used_passwords,
                ]
            ]
        );
        if (isset($data['remove_token']) && $data['remove_token']) {
            $token = $this->di->getRegistry()->getDecodedToken();
            $this->di->getTokenManager()->disableUserToken($token['user_id']);
        }
        if ($user->getModifiedCount()) {
            return ['success' => true, 'message' => 'Password updated successfully'];
        }
        return ['success' => false, 'message' => 'Password not updated'];
    }

    /**
     * Reject passwords that match one of the user's last N stored hashes.
     *
     * Takes the new plaintext (not a hash) because bcrypt hashes are salted per
     * call, so equality against a stored hash is meaningless — we must verify
     * each stored entry with checkHash(), which also covers the legacy format.
     */
    public function handleLastUsedPasswords($plaintextPassword, $lastUsedPasswordsData = [])
    {
        $config = $this->di->getConfig();
        $lastUsedPasswordsCount = $config->path('user.last_used_passwords', 3);

        $lastUsedPasswords = $this->last_used_passwords ?? $lastUsedPasswordsData;

        foreach ($lastUsedPasswords as $storedHash) {
            if ($this->checkHash($plaintextPassword, $storedHash)) {
                return ['success' => false, 'message' => 'Password already used before'];
            }
        }

        if (count($lastUsedPasswords) >= $lastUsedPasswordsCount) {
            array_shift($lastUsedPasswords);
        }

        $lastUsedPasswords[] = $this->hashPassword($plaintextPassword);
        $this->last_used_passwords = $lastUsedPasswords;
    }

    public function sendConfirmationMail($data)
    {
        $confirmationPeriod = 7;

        if (isset($data['confirmation_link'])) {
            $email = isset($data['email']) ? $data['email'] : $this->di->getUser()->email;
            if (!isset($data['username'], $data['name'])) {
                $user = User::findFirst(["email" => strToLower($email)])->toArray();
                $data['username'] = $user['username'];
                $data['name'] = $user['name'];
                $data['user_id'] = $user['user_id'];
            }
            // Disable previous user token
            $this->di->getTokenManager()->disableUserToken($data['user_id']);
            $date1 = new \DateTime("+$confirmationPeriod  days");
            $token = array(
                "user_id" => isset($data['user_id']) ? $data['user_id'] : $this->user_id,
                "exp" => $date1->getTimestamp()
            );
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($token, 'HS256');
            $link = $data['confirmation_link'] . '?token=' . $token;
            // Add extra parameter in confirmation link 
            if (!empty($data['confirmation_data']) && is_array($data['confirmation_data'])) {
                $link = $link . '&' . http_build_query($data['confirmation_data']);
            }
            /**gets template path */
            $templateData = [];
            if (isset($data['templateSource'])) {
                $templateData['marketplace'] = $data['templateSource'];
            }
            $templateData['templateName'] = 'confirmation';
            $templatePath = $this->getTemplatePath($templateData);
            if (empty($templatePath['success'])) {
                return $templatePath;
            }
            $data['email'] = $email;
            $data['path'] = $templatePath['path'];
            $data['link'] = $link;
            $data['subject'] = 'Account Confirmation Mail';
            $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
        }
    }

    public function reLoginUserUsingToken($data)
    {
        if (isset($data['token'])) {
            $coreHelperInstance = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
            $oldDecodedTokenResponse = $coreHelperInstance->decodeToken($data['token']);
            if (isset($oldDecodedTokenResponse['success']) && $oldDecodedTokenResponse['success']) {
                $tokenUsedCount = $oldDecodedTokenResponse['data']['token_regenerated_count'] ?? 0;
                if ($tokenUsedCount > self::MAX_TOKEN_REGENERATION_LIMIT) {
                    return [
                        'success' => false,
                        'message' => "Max Limit reached of Re-Login"
                    ];
                }

                $additionalData = ['token_regenerated_count' => ++$tokenUsedCount];
                $newToken = $this->getToken(
                    $data['token_expiry_duration'] ?? self::DEFAULT_TOKEN_EXPIRATION_TIME,
                    $additionalData
                );
                $tokenDeleteResponse = $this->di->getTokenManager()->removeToken($oldDecodedTokenResponse['data']);
                if ($tokenDeleteResponse) {
                    $newDecodedTokenResponse = $coreHelperInstance->decodeToken($newToken);
                    if (isset($newDecodedTokenResponse['success']) && $newDecodedTokenResponse['success']) {
                        $this->di->getTokenManager()->addTokenToCache($newDecodedTokenResponse['data']);
                        return [
                            'success' => true,
                            'data' => ['token' => $newToken]
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'message' => 'Error in deleting token'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Error in decoding token'
                ];
            }
        }
        return [
            'success' => false,
            'message' => 'Missing Required Fields'
        ];
    }

    public function sendAcknowledgementMail($data)
    {
        /**gets template path */
        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'acknowledgement';
        $templatePath = $this->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }
        $data['path'] = $templatePath['path'];
        $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
    }

    public function sendReportIssueMail($data)
    {
        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'report_issue';
        $templatePath = $this->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }
        $data['path'] = $templatePath['path'];
        $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
    }

    public function reportIssue($issueDetails)
    {
        $userId = $this->di->getUser()->id;
        $subject = $issueDetails['subject'] ?? '';
        $body = $issueDetails['body'] ?? '';
        $userDetails = User::findFirst([['_id' => $userId]]);
        $userEmail = $userDetails->email;
        $username = $userDetails->username;
        $name = $userDetails?->name ?? null;
        $ourEmail = 'sudeepmukherjee@cedcoss.com';
        $acknowledgementMailData = [
            'email' => $userEmail,
            'username' => $username,
            'name' => $name,
            'title' => $subject,
            'text' => 'Sorry for the inconvenience. We have received your issue of "' .
                $body .
                '". We are working hard to fix it. Your issue is our priority.',
            'body' => $body,
            'subject' => 'Sorry for the inconvenience'
        ];
        $reportIssueMailData = [
            'email' => $ourEmail,
            'subject' => $subject,
            'text' => 'We have received an issue from MID -> ' . $userId .
                ', Username -> ' . $username .
                ', Email -> ' . $userEmail .
                '.and Issue is => ' . $body .
                '. Subject of issue => ' . $subject . '.'
        ];
        $this->sendAcknowledgementMail($acknowledgementMailData);
        $this->sendReportIssueMail($reportIssueMailData);
        return [
            'success' => true,
            'message' => $this->di->getLocale()->_('user_issue_received')
        ];
    }

    public function confirmUser($token)
    {
        $decoded = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($token);
        if ($decoded['success'] && $decoded['data']['user_id']) {
            $user = $this->findFirst(["_id" => $decoded['data']['user_id']]);
            if ($user) {
                $user = $this->getCollection()->updateOne(
                    ['_id' => $user->_id],
                    ['$set' => ['confirmation' => 1, 'status' => 2]]
                );
                return ['success' => true, 'message' => 'User Verified Successfully.'];
            } else {
                return ['success' => false, 'code' => 'no_customer_found', 'message' => 'User not found.'];
            }
        } else {
            if ($decoded['code'] == 'token_expired') {
                $decoded['code'] = 'confirmation_token_expired';
            }
            return $decoded;
        }
    }

    /**
     * checks user exists in db using email or username and calls function sendForgotPasswordMail
     * to send the link to the registered user account
     *
     * @param array $data
     * @return array
     */
    public function forgotPassword($data): array
    {
        if (isset($data['email']) && $data['email']) {
            // if email in cache , drop this request
            if ($this->di->getCache()->has($this->cacheKey($data["email"]), "resetMails")) {
                return ["success" => false, "message" => "please try again after sometime."];
            }
            $user = User::findFirst(["email" => $data['email']]);
            if ($user) {
                $data = array_merge($data, $user->toArray());
                $sendResetMail = $user->sendForgotPasswordMail($data);
                $customerDecodedToken = $this->di->getRegistry()->getDecodedToken();
                $this->di->getTokenManager()->disableUserToken($customerDecodedToken['user_id']);

                if (!empty($sendResetMail['success'])) {
                    return [
                        'success' => true,
                        'message' => 'Reset password mail has been sent successfully.'
                    ];
                } else {
                    return $sendResetMail;
                }
            } else {
                return [
                    'success' => false,
                    'code' => 'no_customer_found',
                    'message' => 'No user found with this email.'
                ];
            }
        } elseif (isset($data['username']) && $data['username']) {
            $user = User::findFirst(["username" => $data['username']]);
            if ($user) {
                // if email in cache , drop this request
                if ($this->di->getCache()->has($this->cacheKey($user->email), "resetMails")) {
                    return ["success" => false, "message" => "please try again after sometime."];
                }

                $customerDecodedToken = $this->di->getRegistry()->getDecodedToken();
                $this->di->getTokenManager()->disableUserToken($customerDecodedToken['user_id']);

                $data = array_merge($data, $user->toArray());
                $sendResetMail = $user->sendForgotPasswordMail($data);

                if (!empty($sendResetMail['success'])) {
                    return [
                        'success' => true,
                        'message' => 'Reset password mail has been sent successfully.'
                    ];
                } else {
                    return $sendResetMail;
                }
                return ['success' => true, 'message' => 'Reset password mail has been sent successfully.'];
            } else {
                return ['success' => false, 'code' => 'no_customer_found', 'message' => 'No user found with this username.'];
            }
        } else {
            return ['success' => false, 'code' => 'data_missing', 'message' => 'Request data missing'];
        }
    }

    /**
     * sends the mail to the registered email address with token attach with reset link
     *
     * @param array $data
     * @return mixed
     */
    public function sendForgotPasswordMail($data)
    {
        $emailData = [
            'email' => $data['email'],
            'subject' => $data['subject'] ?? self::DEFAULT_RESET_PASSWORD_MAIL_SUBJECT
        ];
        if (isset($data['reset-link'])) {
            $date = new \DateTime('+24 hour');
            $token = array(
                "user_id" => (string) $this->id,
                "email" => $data['email'],
                'username' => $data['username'],
                "exp" => $date->getTimestamp()
            );
            if ($this->di->getAppCode()->getCurrentApp()) {
                $token['currentApp'] = $this->di->getAppCode()->getCurrentApp();
            }
            $token = $this->di->getObjectManager()
                ->get('App\Core\Components\Helper')
                ->getJwtToken($token, 'HS256', true);

            $link = $data['reset-link'];
            $separator = (strpos($link, '?') === false) ? '?' : '&';
            $link .= $separator . 'token=' . base64_encode($token);

            /**gets template path */
            $templateData = [];
            if (isset($data['templateSource'])) {
                $templateData['marketplace'] = $data['templateSource'];
                $emailTemplate = $this->di->getObjectManager()->get(Helper::class);
                $marketplaceEmailData = $emailTemplate->getResetPasswordDataFromMarketplace($data);
                $emailData = array_merge($emailData, $marketplaceEmailData);
            }
            $templateData['templateName'] = 'reset_password';
            $templatePath = $this->getTemplatePath($templateData);
            if (empty($templatePath['success'])) {
                return $templatePath;
            }
            $emailData['path'] = $templatePath['path'];
            $emailData['link'] = $link;
            $emailData['name'] = $this->name ??
                (isset($emailData['email']) ? (!empty(
                    explode(
                        "@",
                        $data['email']
                    )[0]
                ) ? explode(
                        "@",
                        $data['email']
                    )[0] : 'Seller'
                ) : 'Seller');
            $data = array_merge($emailData, $data);
            $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
            if ($send) {
                // set, check for resetMail in cache
                $this->di->getCache()->set($this->cacheKey($data["email"]), 1, "resetMails", 3600);
                return ['success' => true, 'message' => 'Mail send successfully.'];
            } else {
                return ['success' => false, 'message' => 'Some error occurred while sending mail.'];
            }
        } else {
            return ['success' => false, 'code' => 'data_missing', 'message' => 'Request data missing'];
        }
    }

    /**
     * sends a mail containing password reset link along with token
     *
     * @param array $data
     * @return mixed
     */
    public function sendResetMail($data)
    {
        //@TODO:: provide reset link
        $data['reset-link'] = $this->di->getConfig()->frontend_app_url;
        if (isset($data['reset-link'])) {
            $date = new \DateTime('+24 hour');
            $token = array(
                "user_id" => $this->id,
                "email" => $data['email'],
                "exp" => $date->getTimestamp()
            );

            $token = $this->di->getObjectManager()
                ->get('App\Core\Components\Helper')
                ->getJwtToken($token, 'HS256', false);
            $link = $data['reset-link'] . '?token=' . base64_encode($token);

            //@TODO:: provide path of the template
            $path = 'core' . DS . 'view' . DS . 'email' . DS . 'newuserdetails_resetLink.volt';
            $data['path'] = $path;
            $data['link'] = $link;
            $data['subject'] = 'Password Reset Mail';
            return $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
        } else {
            return ['success' => false, 'code' => 'data_missing', 'message' => 'Request data missing'];
        }
    }

    /**
     * function will validate the password and update the password in db
     *
     * @param array $data
     * @return array
     */
    public function forgotReset($data): array
    {
        if (isset($data['token'], $data['new_password'], $data['confirm_password'])) {
            $decoded = $this->di->getObjectManager()->get('App\Core\Components\Helper')
                ->decodeToken(base64_decode($data['token']), false);
            if ($decoded['success'] && $decoded['data']['email']) {
                if ($this->di->getTokenManager()->checkToken($decoded['data'])) {
                    if (!strcmp($data['new_password'], $data['confirm_password'])) {
                        $isValid = $this->validateRegistrationData(
                            $data,
                            ['username', 'email'],
                            ['password' => 'new_password']
                        );
                        if (!empty($isValid)) {
                            return $isValid;
                        }

                        $alreadyUsed = $this->handleLastUsedPasswords(
                            $data['new_password'],
                            (new static)::findFirst(
                                [
                                    'email' => $decoded['data']['email']
                                ]
                            )->last_used_passwords ?? []
                        );
                        if ($alreadyUsed) {
                            return $alreadyUsed;
                        }

                        $user = $this->getCollection()
                            ->updateOne(
                                ['email' => $decoded['data']['email']],
                                [
                                    '$set' => [
                                        'password' => $this->hashPassword($data['new_password']),
                                        'last_used_passwords' => $this->last_used_passwords,
                                    ]
                                ]
                            );
                        if ($user->getModifiedCount()) {
                            $this->di->getTokenManager()->removeToken($decoded['data']);
                            $this->di->getTokenManager()->disableUserToken($decoded['data']['user_id']);
                            $clientIp = $this->di->getRequest()->getClientAddress();
                            $this->di->getCache()->deleteMultiple([
                                $this->cacheKey($user->username) . "_temp_block_count",
                                $this->cacheKey($user->username) . "_lastlogin",
                                $this->cacheKey($user->username),
                                $this->cacheKey($user->email) . "_temp_block_count",
                                $this->cacheKey($user->email) . "_lastlogin",
                                $this->cacheKey($user->email),
                                $this->cacheKey($clientIp) . "_temp_block_count",
                                $this->cacheKey($clientIp) . "_lastlogin",
                                $this->cacheKey($clientIp)
                            ], "requests");
                            return ['success' => true, 'message' => 'Password has been set successfully.'];
                        } else {
                            return [
                                'success' => false,
                                'code' => 'not_updated',
                                'message' => 'nothing updated'
                            ];
                        }
                    } else {
                        return ['success' => false, 'message' => 'Confirm password not matched with new password!'];
                    }
                } else {
                    return ['success' => false, 'message' => 'Token already used once to reset password.'];
                }
            } else {
                return ['success' => false, 'code' => 'unknown_error', 'message' => 'Something wrong with token.'];
            }
        } else {
            return ['success' => false, 'code' => 'data_missing', 'message' => 'Request data missing'];
        }
    }

    // Get user detail from user and user_data table
    public function getDetails()
    {
        if ($this->id) {
            $first = $this->toArray();
            $parts = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')
                ->getConfig();
            if (isset($parts['profile_pic'])) {
                $parts['profile_pic'] = $this->di->getUrl()->get() . $parts['profile_pic'];
            }
            $final = array_merge($first, $parts);
            return ['success' => true, 'message' => '', 'data' => $final];
        } else {
            return ['success' => false, 'code' => 'no_customer_found', 'message' => 'No Customer Found'];
        }
    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(User::IS_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " = '" . trim(addslashes($value[User::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(User::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " != '" . trim(addslashes($value[User::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(User::IS_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[User::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(User::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " NOT LIKE '%" . trim(addslashes($value[User::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(User::START_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '" . trim(addslashes($value[User::START_FROM])) . "%'";
                } elseif (array_key_exists(User::END_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes($value[User::END_FROM])) . "'";
                } elseif (array_key_exists(User::RANGE, $value)) {
                    if (trim($value[User::RANGE]['from']) && !trim($value[User::RANGE]['to'])) {
                        $conditions[] = "" . $key . " >= '" . $value[User::RANGE]['from'] . "'";
                    } elseif (trim($value[User::RANGE]['to']) && !trim($value[User::RANGE]['from'])) {
                        $conditions[] = "" . $key . " >= '" . $value[User::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "" . $key .
                            " between '" . $value[User::RANGE]['from'] .
                            "' AND '" . $value[User::RANGE]['to'] . "'";
                    }
                }
            }
        }
        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = " MATCH (" .
                $fullTextSearchColumns . ") AGAINST ('" .
                trim(addslashes($filterParams['search'])) .
                "' IN NATURAL LANGUAGE MODE)";
        }
        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }
        return $conditionalQuery;
    }

    public function getALL($limit = 1, $activePage = 1, $filters = [])
    {
        $user = $this->di->getRegistry()->getDecodedToken();
        if (count($filters) > 0) {
            $fullTextSearchColumns = "`username`,`email`";
            $query = 'SELECT * FROM \App\Core\Models\User WHERE ';
            $countQuery = 'SELECT COUNT(*) FROM \App\Core\Models\User WHERE ';
            $conditionalQuery = self::search($filters, $fullTextSearchColumns);
            $query .= $conditionalQuery;
            $countQuery .= $conditionalQuery . ' LIMIT ' . $limit . ' OFFSET ' . $activePage;
            $exeQuery = new Query($query, $this->di);
            $collection = $exeQuery->execute();
            $exeCountQuery = new Query($countQuery, $this->di);
            $collectionCount = $exeCountQuery->execute();
            if ($user['role'] == 'admin') {
                $collectionCount = $collectionCount->toArray();
                $collectionCount[0] = json_decode(json_encode($collectionCount[0]), true);
                return [
                    'success' => true,
                    'message' => '',
                    'data' => [
                        'rows' => $collection,
                        'count' => $collectionCount[0][0]
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'code' => 'not_allowed',
                    'message' => 'You are not allowed to view all users'
                ];
            }
        } else {
            if ($user['role'] == 'admin') {
                $users = User::find(['limit' => $limit, 'offset' => $activePage])->toArray();
                $count = User::count();
                return ['success' => true, 'message' => '', 'data' => ['rows' => $users, 'count' => $count]];
            } else {
                return [
                    'success' => false,
                    'code' => 'not_allowed',
                    'message' => 'You are not allowed to view all users'
                ];
            }
        }
    }

    public function updateStatus($data)
    {
        if ($data && isset($data['id'])) {
            $user = $this->di->getRegistry()->getDecodedToken();
            if ($user['role'] == 'admin') {
                $users = User::findFirst("id='{$data['id']}'");
                $users->save($data, ['status']);
                return ['success' => true, 'message' => 'Status Updated successfully', 'data' => []];
            } else {
                return [
                    'success' => false,
                    'code' => 'not_allowed',
                    'message' => 'You are not allowed to update the status'
                ];
            }
        } else {
            return ['success' => false, 'code' => 'invalid_data', 'message' => 'Invalid data.'];
        }
    }

    public function updateConfirmation($data)
    {
        if ($data && isset($data['id'])) {
            $user = $this->di->getRegistry()->getDecodedToken();
            if ($user['role'] == 'admin') {
                $users = User::findFirst("id='{$data['id']}'");
                $users->save(['confirmation' => 1], ['confirmation']);
                return ['success' => true, 'message' => 'Account Confirmed', 'data' => []];
            } else {
                return ['success' => false, 'code' => 'not_allowed', 'message' => 'You are not allowed to confirm '];
            }
        } else {
            return ['success' => false, 'code' => 'invalid_data', 'message' => 'Invalid data.'];
        }
    }
    /**
     * this function will be used for updating the details of the registered user
     *
     * @param array $data
     * @return array
     */
    public function updateUser($data): array
    {
        $set = [];
        unset($data['password']);
        if (isset($data['new_password'], $data['confirm_password'])) {
            if (!strcmp($data['new_password'], $data['confirm_password'])) {
                $isValid = $this->validateRegistrationData(
                    $data,
                    ['username', 'email'],
                    ['password' => 'new_password']
                );
                if (!empty($isValid)) {
                    return $isValid;
                }
            } else {
                return ['success' => false, 'message' => 'Confirm password not matched with new password!'];
            }

            $alreadyUsed = $this->handleLastUsedPasswords($data['new_password']);
            if ($alreadyUsed) {
                return $alreadyUsed;
            }
            $data['password'] = $this->hashPassword($data['new_password']);
            unset($data['new_password'], $data['confirm_password']);
            $set['last_used_passwords'] = $this->last_used_passwords;
        }
        if (isset($data['email'])) {
            $document['$or'][] = ['email' => $data['email']];
        }
        if (isset($data['username'])) {
            $document['$or'][] = ['username' => $data['username']];
        }

        if (!empty($document) && !empty($this->getCollection()->find($document)->toArray())) {
            return [
                'success' => false,
                'message' => 'Username or Email already exist.'
            ];
        }
        unset($data['confirm_password']);
        $keys = array_keys($data);
        foreach ($keys as $key) {
            $set[$key] = $data[$key];
        }
        // if no data given to update
        if (!$set) {
            return [
                'success' => false,
                'code' => 'invalid_data',
                'message' => 'nothing to update'
            ];
        }
        $user = $this->getCollection()->updateOne(['_id' => $this->_id], ['$set' => $set]);
        if ($user->getModifiedCount()) {
            return [
                'success' => true,
                'code' => 'user_updated',
                'message' => 'User Updated Successfully'
            ];
        } else {
            return [
                'success' => false,
                'code' => 'update_failed',
                'message' => 'user not updated'
            ];
        }
    }

    public function adminUpdateUser($data)
    {
        if ($data && isset($data['id'])) {
            $user = User::findFirst(["id" => $data['id']]);
            if ($user) {
                try {
                    $user->save($data, ['username']);
                    return [
                        'success' => true,
                        'message' => 'User Details updated successfully.'
                    ];
                } catch (\Exception $e) {
                    return [
                        'success' => false,
                        'message' => 'Something went wrong',
                        'code' => 'something_wrong'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'code' => 'no_customer_found',
                    'message' => 'No Customer Found'
                ];
            }
        } else {
            return [
                'success' => false,
                'code' => 'invalid_data',
                'message' => 'Invalid data posted'
            ];
        }
    }

    public function logout()
    {
        if (!$this->di->getSession()->isStarted()) {
            $session = $this->di->getObjectManager()->get('session');
            $session->start();
            $this->di->getSession()->destroy();
        }

        $token = $this->di->getRegistry()->getDecodedToken();
        $success = $this->di->getTokenManager()->removeToken($token);
        $this->di->getSession()->destroy();


        if ($success) {
            $customerIp = $this->di->getRequest()->getClientAddress();
            $this->di->getCache()->delete('token_' . $customerIp);

            $this->setUserStatistics($token['user_id'], 'logout');

            return ['success' => true, 'message' => 'Logout Successfully', 'data' => [1]];
        } else {
            return ['success' => false, 'code' => 'logout_failed', 'message' => 'Logout Failed.'];
        }
    }

    public function getConfigByPath($path, $framework = false)
    {
        if ($this->id) {
            return $this->di->getObjectManager()->create('App\Core\Models\User\Details')
                ->getConfigByKey($path, $this->id);
        } else {
            return [];
        }
    }

    public function setConfig($framework, $path, $value)
    {
        if ($this->id) {
            $this->di->getObjectManager()->create('App\Core\Models\User\Details')
                ->setConfigByKey($path, $value, $this->id);
        } else {
            return [];
        }
    }

    public function getConfig()
    {
        if ($this->id) {
            return $this->di->getObjectManager()
                ->create('App\Core\Models\User\Details')
                ->getConfig($this->id);
        } else {
            return [];
        }
    }

    public function setTrialPeriod($trialDetails)
    {
        $userId = $trialDetails['user_id'];
        $trialPeriod = $trialDetails['trial_value'];
        $saveStatus = $this->setUsertrialPeriod($userId, $trialPeriod);
        if ($saveStatus) {
            return [
                'success' => true,
                'message' => 'Trial period updated for this user'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => explode('_', $saveStatus->getMessages())
            ];
        }
    }

    public function setUsertrialPeriod($userId, $trialPeriod)
    {
        $path = 'payment_plan';
        return $this->di->getObjectManager()->get('\App\Core\Models\User\Details')
            ->setConfigByKey($path, $trialPeriod, $userId);
    }

    public function getUsertrialPeriod($userId)
    {
        $path = 'payment_plan';
        $getTrialPeriod = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')
            ->getConfigByKey($path, $userId);
        if ($getTrialPeriod) {
            $this->di->getObjectManager()->get('\App\Shopify\Components\WebhookActions')
                ->logger(
                    'Got config trial period => ' . $getTrialPeriod['value'] .
                        ' , with user_id => ' . $userId,
                    Logger::CRITICAL,
                    'feed_create_issue.log'
                );
            return $getTrialPeriod['value'];
        } else {
            $this->di->getObjectManager()->get('\App\Shopify\Components\WebhookActions')
                ->logger(
                    'Did not got config trial period. Returned => 7',
                    Logger::CRITICAL,
                    'feed_create_issue.log'
                );
            $this->di->getObjectManager()
                ->get('\App\Core\Models\User\Details')
                ->setConfigByKey($path, 7, $userId);
            return 7;
        }
    }

    public function loginAsUser($proxyUserDetails)
    {
        $proxyLoginId = $proxyUserDetails['id'];
        $userDetails = \App\Core\Models\User::findFirst([['user_id' => $proxyLoginId]]);
        if ($userDetails) {
            $userToken = $userDetails->getToken();
            return ['success' => true, 'message' => 'User token', 'data' => $userToken];
        } else {
            return ['success' => false, 'message' => 'Invalid user id', 'code' => 'invalid_user_id'];
        }
    }

    public function createWebhook($userDetails)
    {
        $userId = $userDetails['user_id'];
        $shop = $userDetails['shop'];
        $webhookStatus = $this->di->getObjectManager()->get('\App\Connector\Components\Webhook')
            ->createWebhooks($shop, $userId);
        if ($webhookStatus) {
            return [
                'success' => true,
                'message' => 'Webhook created successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid API key or access token',
                'code' => 'invalid_api_key_or_access_token'
            ];
        }
    }

    public function getExistingWebhooks($userDetails)
    {
        $userId = $userDetails['user_id'];
        $shop = $userDetails['shop'];
        $allWebhooks = $this->di->getObjectManager()->get('\App\Connector\Components\Webhook')
            ->getExistingWebhooks($shop, $userId);
        if ($allWebhooks) {
            return [
                'success' => true,
                'message' => 'Existing webhooks',
                'data' => $allWebhooks[0]
            ];
        }
        return [
            'success' => false,
            'message' => 'Invalid API key or access token',
            'code' => 'invalid_api_key_or_access_token'
        ];
    }

    //@todo upgrade_needed
    public function getShop()
    {
        $decodedToken = $this->di->getObjectManager()->get('App\Core\Components\Helper')
            ->decodeToken($this->di->getRegistry()->getToken());
        if (isset($decodedToken['data']['shop_id'])) {
            return \App\Shopify\Models\Shop\Details::findFirst(
                ['id="' . $decodedToken['data']['shop_id'] . '"']
            );
        }
        return false;
    }

    /**
     * function is used to get template paths from config
     * @param array $data
     * @return array
     */
    public function getTemplatePath($data): array
    {
        $response = [];
        if (isset($data['templateName'])) {
            $template = $data['templateName'];
            if (!empty($templatePath = $this->di->getConfig()->get('email_templates_path'))) {
                if (isset($data['marketplace'])) {
                    $marketplace = $data['marketplace'];
                    if (!is_null($path = $templatePath->$marketplace->$template)) {
                        $response = [
                            'success' => true,
                            'path' => $path
                        ];
                    } else {
                        $response = [
                            'success' => false,
                            'message' => 'Template not found!'
                        ];
                    }
                } else {
                    if (!is_null($path = $templatePath->$template)) {
                        $response = [
                            'success' => false,
                            'message' => 'Template not found!'
                        ];
                    }
                    $response = [
                        'success' => true,
                        'path' => $templatePath[$template]
                    ];
                }
            } else {
                $response = [
                    'success' => false,
                    'message' => 'Email Template Path Not Set!'
                ];
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'Template name missing!'
            ];
        }
        return $response;
    }

    /**
     * function is used to send uninstall mail
     *
     * @param array $emailData
     * @return array
     */
    public function sendUninstallMail($emailData): array
    {
        $templateData = [];
        if (!isset($emailData['subject'])) {
            $emailData['subject'] = self::DEFAULT_UNINSTALL_APP_MAIL_SUBJECT;
        }
        if (isset($emailData['templateSource'])) {
            $emailTemplate = $this->di->getObjectManager()->get(Helper::class);
            $marketplaceEmailData = $emailTemplate->getUninstallMailDataFromMarketplace($emailData);
            $emailData = array_merge($emailData, $marketplaceEmailData);
            $templateData['marketplace'] = $emailData['templateSource'];
        }
        if (isset($emailData['subject'], $emailData['email'])) {
            if (!filter_var($emailData['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid e-mail Format!'
                ];
            }
            $templateData['templateName'] = 'uninstall';
            $templatePath = $this->getTemplatePath($templateData);
            if (empty($templatePath['success'])) {
                return $templatePath;
            }
            $emailData['path'] = $templatePath['path'];
            $user = $this->di->getUser();
            $emailData['name'] = $user->name ?? (isset($emailData['email'])
                ? (!empty(explode("@", $emailData['email'])[0])
                    ? explode("@", $emailData['email'])[0]
                    : 'Seller')
                : 'Seller');
            $sendMail = $this->di->getObjectManager()
                ->get('App\Core\Components\SendMail')->send($emailData);

            if ($sendMail) {
                return [
                    'success' => true,
                    'message' => "Mail sent successfully"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Some error occurred while sending mail."
                ];
            }
        } else {
            return [
                'success' => false,
                'code' => 'data_missing',
                'message' => "Required data is missing."
            ];
        }
    }

    public function getUserRefreshToken()
    {
        $userId = (string) $this->_id;
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $aclRoleCollection = $baseMongo->getCollection('acl_role');
        $userRefreshRole = $aclRoleCollection->findOne(["code" => "user_refresh_token"]);
        if (empty($userRefreshRole)) {
            $userRefreshRole = $aclRoleCollection->insertOne(
                [
                    "code" => "user_refresh_token",
                    "title" => "user_refresh_token",
                    "description" => "User Refresh Token",
                    "resources" => ""
                ]
            );
            $roleId = $userRefreshRole->getInsertedId();
            $aclResourceCollection = $baseMongo->getCollection('acl_resource');
            $aclResource = $aclResourceCollection->findOne([
                "module" => "core",
                "controller" => "token",
                "action" => "getUserAccessToken"
            ]);
            $aclRoleResourceCollection = $baseMongo->getCollection('acl_role_resource');
            $aclRoleResourceCollection->insertOne([
                "role_id" => $roleId,
                "resource_id" => $aclResource['_id'],
            ]);
        }
        $tokenObject = [
            "role" => 'user_refresh_token',
            "user_id" => $userId,
        ];
        return $this->di->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->getJwtToken($tokenObject, 'RS256', true);
    }


    /**
     * Remove users from user_details, whose email confirmation period expires
     *
     * @param  $data
     * @return void
     */
    public function removeUnconfirmedUser($data)
    {
        $deleteUserAfter = 7;
        $date = new \MongoDB\BSON\UTCDateTime(strtotime("-$deleteUserAfter days") * 1000);
        $query = array(
            'created_at' => array('$lte' => $date),
            'confirmation' => 0
        );

        if (isset($data['user_id'])) {
            $user = $this->getCollection()->findOne(["user_id" => $data['user_id']]);
            if ($user) {
                $query['user_id'] = $data['user_id'];
            }
        }

        $result = $this->getCollection()->deleteMany($query);
        $count = $result->getDeletedCount();
        if ($count) {
            return [
                'success' => true,
                'message' => $count . " user deleted successfully!"
            ];
        }
        return [
            'success' => false,
            'message' => "No unconfirmed user found to delete!"
        ];
    }

    public function setUserStatistics($userId, $action = 'login')
    {
        if (!empty($userId)) {
            $userId = new \MongoDB\BSON\ObjectId($userId);
            switch ($action) {
                case 'login':
                    return $this->getCollection()->updateOne(['_id' => $userId], [
                        '$set' => [
                            'user_stats.last_login' => [
                                'device' => $this->di->getObjectManager()
                                    ->get('\App\Core\Components\Utility')
                                    ->getClientDevice(),
                                'os' => $this->di->getObjectManager()->get('\App\Core\Components\Utility')->getClientOs(),
                                'time' => new \MongoDB\BSON\UTCDateTime()
                            ]
                        ]
                    ]);
                case 'logout':
                    return $this->getCollection()->updateOne(['_id' => $userId], [
                        '$set' => [
                            'user_stats.last_logout' => [
                                'device' => $this->di->getObjectManager()
                                    ->get('\App\Core\Components\Utility')
                                    ->getClientDevice(),
                                'os' => $this->di->getObjectManager()->get('\App\Core\Components\Utility')->getClientOs(),
                                'time' => new \MongoDB\BSON\UTCDateTime()
                            ]
                        ]
                    ]);
                default:
                    return 'Unknown action...';
            }
        }
        return [
            'success' => false,
            'message' => 'user_id not set'
        ];
    }

    public function checkUserExistsAction()
    {
        try {
            $rawBody = $this->getRequestData();
            $userModel = $this->di->getObjectManager()->get('App\Core\Models\User');
            $response = $userModel->checkUserExists($rawBody);
        } catch (\Exception $e) {
            $response = [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ];
        }

        return $this->prepareResponse($response);
    }

    public function checkUserExists($data)
    {
        if (empty($data['username']) || empty($data['email'])) {
            return [
                'success' => false,
                'message' => 'Missing required parameters: username or email.',
            ];
        }

        $filter = [
            '$or' => [
                ['email' => $data['email']],
                ['username' => $data['username']],
            ]
        ];

        try {
            $user = $this->findFirst($filter);

            if ($user && isset($user->_id)) {
                return [
                    'success' => true,
                    'message' => 'Username or email already exists.',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Username and email do not exist.',
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'An error occurred while checking user existence: ' . $e->getMessage(),
            ];
        }
    }


}
