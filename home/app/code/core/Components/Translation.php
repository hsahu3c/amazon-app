<?php

namespace App\Core\Components;

use Phalcon\Translate\InterpolatorFactory;
use Phalcon\Translate\TranslateFactory;

class Translation extends Base
{
    protected $locale = false;
    protected $configKey = 'translation';

    public function setDi(\Phalcon\Di\DiInterface $di): void
    {
        parent::setDi($di);
        if (!$this->locale) {
            $this->registerTranslation();
        }
    }
    /**
     * Registers locale to DI
     *
     * @return void.
     *
     */
    public function registerTranslation()
    {
        $headers = $this->di->getRequest()->getHeaders();
        $localeCode = $headers['Locale'] ?? ($headers['locale'] ?? 'en');

        if (!($translations = $this->di->getCache()->get('locale_content_' . $localeCode))) {
            $translations = [];
            $modules = $this->di
                ->getObjectManager()
                ->get('App\Core\Components\Helper')
                ->getAllModules();
            foreach ($modules as $name => $status) {
                $filePath = CODE . DS . $name . DS . 'translation' . DS . $localeCode . '.php';
                if (file_exists($filePath)) {
                    $moduleTranslations = require $filePath;
                    $translations = array_merge($translations, $moduleTranslations);
                }
            }

            if (empty($translations)) {
                $localeCode = 'en';
                $translations = $this->getTranslatedContent(['locale' => $localeCode]);
            }
            $this->di->getCache()->set('locale_content_' . $localeCode, $translations);
        }
        $interpolator = new InterpolatorFactory();
        $factory      = new TranslateFactory($interpolator);
        $newlocale = $factory->newInstance(
            'array',
            [
                'content' => $translations,
            ]
        );
        $this->di->set('locale', $newlocale);
        $this->locale = $newlocale;
    }

    /**
     * used to get the translated content file data of all modules.
     *
     * @param array $data
     * @return array
     */
    public function getTranslatedContent($data = [])
    {
        $localeCode = $data['locale'] ?? 'en';

        $translations = [];
        if ($this->di->getCache()->has('locale_content_' . $localeCode)) {
            $translations = $this->di->getCache()->get('locale_content_' . $localeCode);
        } else {
            $modules = $this->di
                ->getObjectManager()
                ->get('App\Core\Components\Helper')
                ->getAllModules();
            foreach ($modules as $name => $status) {
                $filePath = CODE . DS . $name . DS . 'translation' . DS . $localeCode . '.php';
                if (file_exists($filePath)) {
                    $moduleTranslations = require $filePath;
                    $translations = array_merge($translations, $moduleTranslations);
                }
            }
            $this->di->getCache()->set('locale_content_' . $localeCode, $translations);
        }

        return $translations;
    }

    /**
     * used to check whether the process code is eligible for translation or not
     *
     * @param array $data
     * @return boolean
     */
    public function isProcessTranslatable($data = [])
    {
        $response = false;
        $translationConfig = $this->getTranslationConfig($data);
        if (empty($translationConfig) || empty($data['process_code'])) {
            return $response;
        }

        if (!empty($translationConfig[$data['process_code']])) {
            $response = true;
        }
        return $response;
    }

    /**
     * used to get the translation config of specific marketplaces
     *
     * @param array $data
     * @return array
     */
    public function getTranslationConfig($data = [])
    {
        # todo to use caching to store source_target config.
        $config = [];
        $headers = $this->di->getRequest()->getHeaders();
        $sourceMarketplace = $data['source'] ?? ($headers['Ced-Source-Name'] ?? '');
        $targetMarketplace = $data['target'] ?? ($headers['Ced-Target-Name'] ?? '');

        if (!$this->di->getConfig()->has($this->configKey)) {
            return $config;
        }
        $translationConfig = $this->di->getConfig()->get($this->configKey);

        if (!empty($sourceMarketplace) && !empty($translationConfig[$sourceMarketplace][$data['process_code']])) {
            $config = $translationConfig[$sourceMarketplace];
        }

        if (!empty($targetMarketplace) && !empty($translationConfig[$targetMarketplace][$data['process_code']]) && !empty($config)) {
            foreach ($translationConfig[$targetMarketplace] as $key => $value) {
                if (is_array($value) && !empty($config[$key])) {
                    $config[$key] = array_merge($config[$key], $value);
                }
            }
        }
        if (!empty($targetMarketplace) && !empty($translationConfig[$targetMarketplace][$data['process_code']]) && empty($config)) {
            $config = $translationConfig[$targetMarketplace];
        }
        return $config;
    }
}
