<?php

namespace App\Core\Components;

use Phalcon\Logger\Logger;
use App\Core\Models\Config\Config;
use Phalcon\Mvc\View\Engine\Volt\Compiler as VoltCompiler;

class SendMail extends Base
{
    const IGNORE_EMAIL_TRANSLATION_INDEXS = [
        'banner', 'path', 'email', 'replayTo', 'bccs', 'ccs', 'files'
    ];
    public function send($data)
    {
        $compiler = new VoltCompiler;
        //$this->di->getLog()->logContent('Mail Data '.json_encode($data) ,Logger::CRITICAL,'mail.log');
        // Compile a template in a file specifying the destination file
        $path = BP . DS . 'var' . DS . 'compile' . DS . 'email' . DS;
        if (!file_exists($path)) {
            $oldmask = umask(0);
            mkdir($path, 0777, true);
            umask($oldmask);
        }
        $data['banner'] = $this->di->getConfig()->backend_base_url . 'media/680x300.png';
        $compiler->setOption('path', $path);
        $compiler->setOption('separator', '-');
        if(empty($data['path'])) {
            throw new \Exception('path is required');
        }
        $template = $this->findTemplate($data['path']);
        $data = $this->getTranslatedTemplateData($data);
        $compiler->compile($template);
        $template = $compiler->getCompiledTemplatePath();
        extract($data);
        ob_start();
        require $template;
        $content = ob_get_clean();
        $email = $data['email'];
        $mailerConfig = $this->di->getConfig()->get('mailer');
        if (!empty($data['replyTo'])) {
            if (!empty($data['replyTo']['replyToEmail']) && !empty($data['replyTo']['replyToName'])) {
                $replyTo['replyToEmail'] = $data['replyTo']['replyToEmail'];
                $replyTo['replyToName'] = $data['replyTo']['replyToName'];
            }
        } elseif (!empty($mailerConfig['reply_to_email']) && !empty($mailerConfig['reply_to_name'])) {
            $replyTo['replyToEmail'] = $mailerConfig['reply_to_email'];
            $replyTo['replyToName'] = $mailerConfig['reply_to_name'];
        }
        if (strpos($email, 'duplicate_') !== false) {
            $user = \App\Core\Models\User::findFirst(["email" => $email])->toArray();
            if (!empty($user) && isset($user['source_email'])) {
                $email = $user['source_email'];
            }
        }

        if ($this->di->getConfig()->enable_rabbitmq && $this->di->getConfig()->mail_through_rabbitmq) {
            $handlerData = [
                'type' => 'class',
                'class_name' => 'Qhandler',
                'method' => 'sendMail',
                'queue_name' => 'general',
                'data' => [
                    'email' => $email,
                    'subject' => $data['subject'] ?? '',
                    'content' => base64_encode($content),

                ],
                'bearer' => $this->di->getConfig()->get('rabbitmq_token')
            ];
            if (isset($data['bccs'])) {
                $handlerData['data']['bccs'] = $data['bccs'];
            }
            if (isset($data['ccs'])) {
                $handlerData['data']['ccs'] = $data['ccs'];
            }
            if ($this->di->getConfig()->enable_rabbitmq_internal) {
                $this->di->getLog()->logContent(
                    'Rabbitmq Internal Mail adding data ' . json_encode($handlerData),
                    Logger::CRITICAL,
                    'mail.log'
                );
                $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                $responseData = ['feed_id' => $helper->createQueue($handlerData['queue_name'], $handlerData), 'success' => true];
            } else {
                $this->di->getLog()->logContent('Rabbitmq External Mail', Logger::CRITICAL, 'mail.log');
                $this->di->get('\App\Core\Components\Helper')->curlRequest(
                    $this->di->getConfig()->rabbitmq_url . '/rmq/queue/create',
                    $handlerData,
                    false
                );
            }
        } else {
            // $this->di->getLog()->logContent(
            //     'Sending mail directly',
            //     Logger::CRITICAL,
            //     'mail.log'
            // );
            $mailer = $this->di->getObjectManager()->get('mailer');

            return $mailer->send($email, $data['subject'] ?? "", $content, [
                'replyTo' => $replyTo ?? [],
                'bccs' => $data['bccs'] ?? [],
                'ccs' => $data['ccs'] ?? [],
                'isHtml' => true,
                'debug' => 0,
                'files' => $data['files'] ?? []
            ]);
        }
    }

    /**
     * @param $path
     * @return string
     * @throws \Exception
     */
    public function findTemplate($path)
    {
        $findInPaths = [
            BP . DS . 'app' . DS . 'design' . DS,
            BP . DS . 'app' . DS . 'code' . DS
        ];
        if (file_exists($path)) {
            return $path;
        }
        foreach ($findInPaths as $basePath) {
            if (file_exists($basePath . $path)) {
                return $basePath . $path;
            }
        }
        throw new \Exception('Template not found');
    }

    private function getTranslatedTemplateData($data)
    {
        $configData = $this->di->getConfig();
        $configContainer = $this->di->getObjectManager()->get(Config::class);
        $translation = $this->di->getTranslation();

        $locale = 'en';
        if (
            !empty($configData['ignore_email_translation_template']) &&
            !empty($configData['email_translation']) &&
            !in_array($data['path'], $configData['ignore_email_translation_template'])
        ) {
            $configContainer->reset();
            $configContainer->setUserId();
            $configContainer->setGroupCode('locale');
            $userConfigData = $configContainer->getConfig('locale');
            $locale = !empty($userConfigData['email_locale']) ? $userConfigData['email_locale'] : 'en';
        }
        
        $translationContent = $translation->getTranslatedContent(['locale' => $locale]);

        if (
            $locale != 'en' &&
            !empty($configData['ignore_email_translation_template']) &&
            !empty($configData['email_translation']) &&
            !in_array($data['path'], $configData['ignore_email_translation_template'])
        ) {
            foreach ($data as $key => $value) {
                if (!in_array($key, self::IGNORE_EMAIL_TRANSLATION_INDEXS) && is_string($value)) {
                    $data[$key] = $translationContent[$value] ?? $value;
                }
            }
        }
        $paths = explode(DS, $data['path']);
        $moduleName = $paths[0] ?? '';
        $paths = $paths[(count($paths) - 1)];
        $templateName = (explode('.', $paths))[0] ?? '';
        if (!empty($templateName)) {
            if (empty($moduleName) || in_array(strtolower($moduleName), ['core'])) {
                $templateKey = 'mail_' . $templateName;
            } else {
                $templateKey = $moduleName . '_mail_' . $templateName;
            }
            if (empty($templateKey) || empty($translationContent[$templateKey])) {
                return $data;
            }
            $translationContent = $translationContent[$templateKey];
            $data = array_merge($translationContent, $data);
        }

        return $data;
    }
}