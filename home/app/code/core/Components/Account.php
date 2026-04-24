<?php

namespace App\Core\Components;

use App\Core\Components\Email\Helper;
use App\Core\Models\User;

class Account extends Base
{
    const ACCOUNT_BLOCK_TEMPLATE_NAME = 'account_block';

    const ACCOUNT_DEACTIVATE_TEMPLATE_NAME = 'account_deactivate';

    const ACCOUNT_BLOCK_MAIL_SUBJECT = 'Account Temporary Blocked';

    const ACCOUNT_DEACTIVATE_MAIL_SUBJECT = 'Account deactivated';

    const LAST_LOGIN = "_lastlogin";

    const TEMP_BLOCK_COUNT = "_temp_block_count";

    private $mongoConnection;

    /**
     * Generate a non-reversible cache key for the given value.
     * Uses SHA-256 because MD5 is flagged as insecure by static analysis,
     * even when (as here) the hash is only a cache-key generator.
     */
    private function cacheKey($value)
    {
        return hash('sha256', (string)$value);
    }

    public function changeStatus($usernames, $isSub, $action)
    {
        $foundArray = [];
        $notFoundArray = [];
        $this->mongoConnection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getConnection();
        $usernames = explode(",", $usernames);
        if ($isSub) {
            foreach ($usernames as $username) {
                if ($this->mongoConnection->sub_user->findOne(['username' => $username])) {
                    $this->mongoConnection->sub_user->updateOne(['username' => $username], ['$set' => [
                        'status' => $action == 'activate' ? 2 : 3
                    ]]);
                    array_push($foundArray, [$username => $action == 'activate' ? 'activated' : 'deactivated']);
                } else {
                    array_push($notFoundArray, $username);
                }
            }
            return ['result' => $foundArray, 'errors' => $notFoundArray];
        }
        foreach ($usernames as $username) {
            if ($this->mongoConnection->user_details->findOne(['username' => $username])) {
                $this->mongoConnection->user_details->updateOne(['username' => $username], ['$set' => [
                    'status' => $action == 'activate' ? 2 : 3
                ]]);
                array_push($foundArray, [$username => $action == 'activate' ? 'activated' : 'deactivated']);
            } else {
                array_push($notFoundArray, $username);
            }
        }
        return ['result' => $foundArray, 'errors' => $notFoundArray];
    }

    /**
     * identifies is user ip exists in whitelist ip array
     *
     * @return boolean
     */
    public function validateUserIp()
    {
        if ($this->di->getConfig()->get('whitelist_ip')) {
            $whiteListIps = $this->di->getConfig()->get("whitelist_ip")->toArray();
            if (count($whiteListIps) > 0) {
                $clientIp = $this->di->getRequest()->getClientAddress();
                if (in_array($clientIp, $whiteListIps)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * identifies false login attempts and counts them
     *
     * @param mixed $user
     * @param boolean $isSub
     * @return void
     */
    public function handleAttempts($user, $isSub = false)
    {
        if ($this->di->get('isDev') || $this->validateUserIp()) {
            return true;
        }

        $clientIp = $this->di->getRequest()->getClientAddress();
        $username = ($user->username ?? $clientIp);
        $email = ($user->email ?? $clientIp);
        if ($isSub) {
            $username .= "_sub";
            $email .= "_sub";
        }
        $bansConfig = $this->di->getConfig()->get("bans");
        $cache = $this->di->getCache();
        $tempBlockDuration = $bansConfig->get("temp_block_duration");
        $maxCacheTtl = $bansConfig->get("max_cache_ttl");
        $maxTempBlock = $attemptLeft = $bansConfig->get("max_temp_block");
        // check user ref in cache
        if ($cache->has($this->cacheKey($username), "requests") || $cache->has($this->cacheKey($email), "requests")) {
            if (
                $cache->get($this->cacheKey($username), "requests") >= $maxTempBlock &&
                (time() - $cache->get($this->cacheKey($username) . self::LAST_LOGIN, "requests")) > $tempBlockDuration
            ) {
                $cache->set($this->cacheKey($username), 1, "requests", $maxCacheTtl);
                $cache->set($this->cacheKey($username) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
            } elseif (
                $cache->get($this->cacheKey($email), "requests") >= $maxTempBlock &&
                (time() - $cache->get($this->cacheKey($email) . self::LAST_LOGIN, "requests")) > $tempBlockDuration
            ) {
                $cache->set($this->cacheKey($email), 1, "requests", $maxCacheTtl);
                $cache->set($this->cacheKey($email) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
            } else {
                // increase the key count by 1
                $attemptLeft = $attemptLeft - (int)$cache->get($this->cacheKey($username), "requests");
                $cache->set($this->cacheKey($username), (int)$cache->get($this->cacheKey($username), "requests") + 1, "requests", $maxCacheTtl);
                $cache->set($this->cacheKey($username) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
                // for email
                $cache->set($this->cacheKey($email), (int)$cache->get($this->cacheKey($email), "requests") + 1, "requests", $maxCacheTtl);
                $cache->set($this->cacheKey($email) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
            }
        } else {
            $cache->set($this->cacheKey($username), 1, "requests", $maxCacheTtl);
            $cache->set($this->cacheKey($username) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
            // for email
            $cache->set($this->cacheKey($email), 1, "requests", $maxCacheTtl);
            $cache->set($this->cacheKey($email) . self::LAST_LOGIN, time(), "requests", $maxCacheTtl);
        }
        return $attemptLeft;
    }
    /**
     * checks any false attempts in cache, then bans or counts them
     *
     * @param array $data
     * @param boolean $isSub
     * @return array
     */
    public function handleIfBlocked($data, $isSub = false)
    {
        $locale = $this->di->getLocale();
        $adminMail = $this->di->getConfig()->get("mailer")->get("sender_email");
        $userName = $data["username"] ?? $data["email"] ?? "default";
        $identifier = isset($data["username"]) ? "username" : (isset($data["email"]) ? "email" : "ip");
        $clientIp = $this->di->getRequest()->getClientAddress();
        $cache = $this->di->getCache();
        $bansConfig = $this->di->getConfig()->get("bans");
        $tempBlockDuration = $bansConfig->get("temp_block_duration") ?? 86400;
        $ipBlockDuration = $bansConfig->get("ip_block_duration") ?? 604800;
        $maxCacheTtl = $bansConfig->get("max_cache_ttl") ?? 86400;
        $maxTempBlock = $bansConfig->get("max_temp_block") ?? 3;
        if ($isSub) {
            $userName .= "_sub";
            $clientIp .= "_sub";
        }
        // check ip in cache blacklist
        if ($cache->has($this->cacheKey($clientIp), "blacklist")) {
            return [
                'success' => false,
                'message' => $locale->_("ip banned"),
                'code' => 'status_not_active',
                'data' => []
            ];
        }
        // check if already temp blocked
        if (
            $cache->get($this->cacheKey($userName) . self::TEMP_BLOCK_COUNT, "requests") >= 1 &&
            $cache->get($this->cacheKey($userName) . self::TEMP_BLOCK_COUNT, "requests") < $maxTempBlock
        ) {
            $cache->set(
                $this->cacheKey($userName) . self::TEMP_BLOCK_COUNT,
                $cache->get($this->cacheKey($userName) . self::TEMP_BLOCK_COUNT, "requests") + 1,
                "requests",
                $maxCacheTtl
            );
            $this->di->getLog()->logContent(
                print_r("user $userName has been temporary blocked.", true),
                'info',
                'debugging.log'
            );
            return [
                'success' => false,
                'message' => $locale->_("account blocked"),
                'code' => 'account_temp_blocked',
                'data' => []
            ];
        }
        //check if temporary blocked via username
        if (
            $cache->get($this->cacheKey($userName), "requests") >= $maxTempBlock
            &&  time() - $cache->get($this->cacheKey($userName) . self::LAST_LOGIN, "requests") < $tempBlockDuration
        ) {
            if ($cache->get($this->cacheKey($userName) . self::TEMP_BLOCK_COUNT,  "requests") >= $maxTempBlock) {
                $this->disableAccount(
                    $userName,
                    "Account deactivated for more than $maxTempBlock consecutive temporary blocks."
                );
                $this->sendAccountDeactivateMail([
                    "email" => $identifier == "username" ?
                        $this->resolveAttributes($userName, "username", "email") :
                        explode("_", $userName)[0],
                    "username" => $identifier == "email" ?
                        $this->resolveAttributes($userName, "email", "username") :
                        explode("_", $userName)[0],
                    "name" => $identifier == "email" ?
                        $this->resolveAttributes($userName, "email", "name") :
                        explode("_", $userName)[0],
                    "subject" => self::ACCOUNT_DEACTIVATE_MAIL_SUBJECT,
                    "marketplace" => isset($data['templateSource']) ? $data['templateSource'] : "",
                    "content" => $locale->_(
                        "email account deactivated",
                        [
                            "username" => $identifier == "email" ?
                                $this->resolveAttributes($userName, "email", "username") :
                                explode("_", $userName)[0],
                            "email" => $adminMail
                        ]
                    ),
                    "bcc" => [$this->di->getCache("mailer")->get("sender_email")]
                ]);
                return [
                    'success' => false,
                    'message' => $locale->_("account deactivated"),
                    'code' => 'status_not_active',
                    'data' => []
                ];
            }
            $cache->set(
                $this->cacheKey($userName) . self::TEMP_BLOCK_COUNT,
                $cache->get($this->cacheKey($userName) . self::TEMP_BLOCK_COUNT, "requests") + 1,
                "requests",
                $maxCacheTtl
            );
            $this->di->getLog()->logContent(
                print_r("user $userName has been temporary blocked.", true),
                'info',
                'debugging.log'
            );
            $this->sendAccountBlockMail([
                "email" => $identifier == "username" ?
                    $this->resolveAttributes($userName, "username", "email") :
                    explode("_", $userName)[0],
                "username" => $identifier == "email" ?
                    $this->resolveAttributes($userName, "email", "username") :
                    explode("_", $userName)[0],
                "name" => $identifier == "email" ?
                    $this->resolveAttributes($userName, "email", "name") :
                    explode("_", $userName)[0],
                "subject" => self::ACCOUNT_BLOCK_MAIL_SUBJECT,
                "content" =>  $locale->_(
                    "email account blocked",
                    [
                        "username" => $identifier == "email" ? $this->resolveAttributes($userName, "email", "username") : explode("_", $userName)[0],
                        "email" => $adminMail
                    ]
                ),
                "marketplace" => isset($data['templateSource']) ? $data['templateSource'] : ""
            ]);
            return [
                'success' => false,
                'message' => $locale->_("account blocked"),
                'code' => 'account_temp_blocked',
                'data' => []
            ];
        }
        //check if temporary blocked via ip
        if (
            $cache->get($this->cacheKey($clientIp), "requests") >= $maxTempBlock &&
            time() - $cache->get($this->cacheKey($clientIp) . self::LAST_LOGIN, "requests") < $tempBlockDuration
        ) {
            if ($cache->get($this->cacheKey($clientIp) . self::TEMP_BLOCK_COUNT,  "requests") >= $maxTempBlock) {
                $cache->set($this->cacheKey($clientIp), 1, "blacklist", $ipBlockDuration);
                $cache->deleteMultiple(
                    [
                        $this->cacheKey($clientIp) . self::LAST_LOGIN,
                        $this->cacheKey($clientIp) . self::TEMP_BLOCK_COUNT,
                        $this->cacheKey($clientIp),
                    ],
                    "requests"
                );
                $this->sendAccountDeactivateMail([
                    "email" => $this->di->getConfig("mailer")->get("sender_email"),
                    "subject" => "Ip banned",
                    "content" => $locale->_("email ip banned", ["ip" => explode("_", $clientIp)[0]]),
                ]);
                return [
                    'success' => false,
                    'message' => $locale->_("ip banned"),
                    'code' => 'status_not_active',
                    'data' => []
                ];
            }
            $cache->set(
                $this->cacheKey($clientIp) . self::TEMP_BLOCK_COUNT,
                $cache->get(
                    $this->cacheKey($clientIp) . self::TEMP_BLOCK_COUNT,
                    "requests"
                ) + 1,
                "requests",
                $maxCacheTtl
            );
            return [
                'success' => false,
                'message' => $locale->_("ip blocked"),
                'code' => 'account_temp_blocked',
                'data' => []
            ];
        }
        return ['success' => true];
    }
    /**
     * returns email from username or vice-versa
     *
     * @param string $name
     * @param string $resolveFrom
     * @param string $resolveTo
     * @return mixed
     */
    public function resolveAttributes($name, $resolveFrom = "username", $resolveTo = "email")
    {
        $isSubUser = substr($name, -4) == "_sub" ? true : false;
        if ($isSubUser) {
            $user = new \App\Core\Models\User\SubUser();
            $user->init();
            $username = substr($name, 0, strlen($name) - 4);
            $user = $user::findFirst([$resolveFrom => $username]);
            print_r($user->username);
            return $user->$resolveTo ?? false;
        }
        $userModel = $this->di->getObjectManager()->get("App\Core\Models\User");
        $userModel = $userModel->findFirst([$resolveFrom => $name]);
        return $userModel->$resolveTo ?? false;
    }
    /**
     * disables account in db by setting status to 3
     * automatically identifies if it's a user or sub user
     * @param string $username
     * @param string $reason
     * @return bool
     */
    public function disableAccount($username, $reason = "")
    {
        $isSubUser = substr($username, -4) == "_sub" ? true : false;
        if ($isSubUser) {
            $user = new \App\Core\Models\User\SubUser();
            $user->init();
            $username = substr($username, 0, strlen($username) - 4);
            $user = $user::findFirst([
                '$or' => [
                    ['email' => $username],
                    ['username' => $username],
                ]
            ]);
            if ($user) {
                $user->status = 3;
                $user->reason = $reason;
                $user->save();
                $this->di->getCache()->deleteMultiple(
                    [
                        $this->cacheKey($user->username . "_sub") . self::LAST_LOGIN,
                        $this->cacheKey($user->username . "_sub") . self::TEMP_BLOCK_COUNT,
                        $this->cacheKey($user->username . "_sub") . "",
                        $this->cacheKey($user->email . "_sub") . self::LAST_LOGIN,
                        $this->cacheKey($user->email . "_sub") . self::TEMP_BLOCK_COUNT,
                        $this->cacheKey($user->email . "_sub") . ""
                    ],
                    "requests"
                );
                return true;
            }
            return false;
        }
        $user = new \App\Core\Models\User;
        $user = $user::findFirst([
            '$or' => [
                ['email' => $username],
                ['username' => $username],
            ]
        ]);
        if ($user) {
            $user->status = 3;
            $user->reason = $reason;
            $user->save();
            $this->di->getCache()->deleteMultiple(
                [
                    $this->cacheKey($user->username) . self::LAST_LOGIN,
                    $this->cacheKey($user->username) . self::TEMP_BLOCK_COUNT,
                    $this->cacheKey($user->username),
                    $this->cacheKey($user->email) . self::LAST_LOGIN,
                    $this->cacheKey($user->email) . self::TEMP_BLOCK_COUNT,
                    $this->cacheKey($user->email)
                ],
                "requests"
            );
            return true;
        }
        return false;
    }

    /**
     * send account deactivate mail
     *
     * @param array $data
     * @return void
     */
    public function sendAccountDeactivateMail($data)
    {
        $data['templateName'] = self::ACCOUNT_DEACTIVATE_TEMPLATE_NAME;
        if (empty($data['marketplace'])) {
            unset($data['marketplace']);
        } else {
            $emailTemplate = $this->di->getObjectManager()->get(Helper::class);
            $marketPlaceEmailData = $emailTemplate->getDeactivateMailDataFromMarketplace($data);
            $data = array_merge($data, $marketPlaceEmailData);
        }
        $templatepath = $this->di->getObjectManager()->get('\App\Core\Models\User')->getTemplatePath($data);
        $data['path'] = $templatepath['path'];
        $this->di->getObjectManager()->get('\App\Core\Components\SendMail')->send($data);
    }

    /**
     * send temporary block email
     *
     * @param array $data
     * @return void
     */
    public function sendAccountBlockMail($data)
    {
        $data['templateName'] = self::ACCOUNT_BLOCK_TEMPLATE_NAME;
        if (empty($data['marketplace'])) {
            unset($data['marketplace']);
        } else {
            $emailTemplate = $this->di->getObjectManager()->get(Helper::class);
            $marketPlaceEmailData = $emailTemplate->getBlockMailDataFromMarketplace($data);
            $data = array_merge($data, $marketPlaceEmailData);
        }
        $templatepath = $this->di->getObjectManager()->get('\App\Core\Models\User')->getTemplatePath($data);
        $data['path'] = $templatepath['path'];
        $this->di->getObjectManager()->get('\App\Core\Components\SendMail')->send($data);
    }
}
