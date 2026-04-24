<?php

namespace App\Connector\Components;

use App\Connector\Models\Locale\ProductLocale;
use App\Core\Components\Base;

class LocaleManager extends Base
{
    const MODEL_SELECTOR = [
        'product' => ProductLocale::class
    ];

    public function updateLocale($data)
    {
        try {
            $modelSelector = $data['selector'] ?? self::MODEL_SELECTOR['product'];
            $localeManager = $this->di->getObjectManager()->get($modelSelector);
            return $localeManager->saveLocale($data);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $this->di->getLocale()->_('Somthing went wrong!'),
                'error_message' => $e->getMessage()
            ];
        }
    }

}
