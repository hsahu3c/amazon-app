<?php

namespace App\Connector\Models\Locale;

interface LocaleManager
{
    const DEFAULT_LIMIT = 50;

    const SUPPORTED_LOCALES = [
        'es',
        'fr',
        'ga',
        'de',
        'it'
    ];

    public function saveLocale($data);
}
