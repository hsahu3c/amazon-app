<?php

namespace App\Connector\Components;

class ImportHelper extends \App\Core\Components\Base
{

    public function getBaseAttributes()
    {
        return (new \App\Connector\Models\Product())->getBaseAttributes();
    }

    public function getMappingSuggestions($sourceAttrs, $userId = false, $source = false, $target = 'connector')
    {
        $suggestion = $this->di->getObjectManager()->get('suggestionHelper')
            ->getSuggestion($sourceAttrs, $userId, $source, $target);
        return $suggestion;
    }
}
