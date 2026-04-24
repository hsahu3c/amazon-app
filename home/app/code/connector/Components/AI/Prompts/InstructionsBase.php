<?php

namespace App\Connector\Components\AI\Prompts;

use App\Core\Models\BaseMongo;
class InstructionsBase extends BaseMongo
{
    public $marketplace;

    public $Instructions;

    public function init(): void {
        $this->marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName()??"amazon";
        $this->marketplace = ucfirst($this->marketplace);

        $this->Instructions = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\Instructions"."\\".$this->marketplace."\MapCategories")->mapping;
    }

    public function getInstruction($key, $for)
    {
        $this->init();
        if(isset($this->Instructions[$key])){
            return $this->getForInstructions($key,$for);
        }

            return $this->getForInstructions('default',$for);
        }

    public function getForInstructions($key, $for)
    {
        switch ($for) {
            case "title":
                $helper = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\Instructions"."\\".$this->marketplace."\TitleData");
                return $helper->getTitleInstruction($this->Instructions[$key]);
            case "description":
                $helper = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\Instructions"."\\".$this->marketplace."\DescriptionData");
                return $helper->getDescriptionInstruction($this->Instructions[$key])??false;
            case "bullet_points":
                $helper = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\Instructions"."\\".$this->marketplace."\BulletPointData");
                return $helper->getBulletPointInstruction($this->Instructions[$key]);

            default:
                return [
                    'format' => [
                        'parent' => "",
                        'child' => ""
                    ],
                    'instructions' => "",
                    'additionals' => []
                ];
        }
    }
}
