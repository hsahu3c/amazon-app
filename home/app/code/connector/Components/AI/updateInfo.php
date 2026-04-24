<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class updateInfo extends BaseMongo
{

    public $_user_id;

    public $_product_seo_details_table;

    public function init($data): void
    {
        if (isset($data['user_id'])) {
            $this->_user_id = $data['user_id'];
        } else {
            $this->_user_id = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_product_seo_details_table = $mongo->getCollectionForTable('product_seo_details');
    }

    /**
     *
     * @param [string] $result
     * @return bool
     */
    public function checkIfValidResult($result)
    {
        //TODO add in config
        $arrOfStrInvalid = ["as an AI language model", "provide more info", "sorry I don't know", "AI model"];

        $flag = true;

        if ($result == "")
            return false;

        foreach ($arrOfStrInvalid as $str) {
            if (str_contains($result, $str))
                $flag = false;
        }

        return $flag;
    }

    public function formatBulletResult($data)
    {

        $resResult = explode('\n', $data['result']);
        $incluesArr = [')', '.', '-'];
        $prepareSetArr = [];
        foreach ($resResult as $k => $v) {
            $resResult[$k] = trim($v);
            if($resResult[$k] != ''){
                if ($resResult[$k][0] == '-') {
                    $resResult[$k] = substr($resResult[$k], 1);
                }

                if (in_array($resResult[$k][1], $incluesArr)) {
                    $resResult[$k] = substr($resResult[$k], 2);
                }

                $resResult[$k] = trim($resResult[$k]);
                $prepareSetArr[] = [
                    'result' => $resResult[$k],
                    'prompt' => $data['prompt'][$k] ?? '',
                    'tone' => $data['tone'] ?? 'no_tone_set',
                    'index' => $k + 1
                ];
            }
        }

        return $prepareSetArr;
    }


    /**
     *
     * @param [array] $data
     * $data = ["prompt" => "", "result" => "", 'for'=> 'title' / 'description', 'source_product_id' => '12234455']
     * @return array
     */
    public function updateGeneratedPrompt($data)
    {
        $this->init($data);

        $resultValid = $data['for'] == 'category_suggestions' ? (count($data['result']) == 0 ?  false : true) : $this->checkIfValidResult($data['result']);

        $target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $for = $data['for'];

        if (!$resultValid) {
            return ['success' => false, 'message' => 'Result not valid', 'code' => 'result_invalid'];
        }


        $def_search = ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace, 'source_product_id' => $data['source_product_id']];



        $pushObj = ['prompt' => $data['prompt'], 'result' => $data['result'],'tone' => $data['tone'] ?? 'no_tone_set'];

        if(isset($data['additional_history']) && is_array($data['additional_history'])){
            $pushObj = $pushObj + $data['additional_history'];
        }

        if ($for == 'bullet_points') {
            $bulltetPointSet = ['$each' => $this->formatBulletResult($data)];
        }

        $setArray = [
            '$push' => ["history.{$for}" => $bulltetPointSet ? $bulltetPointSet : $pushObj],
            '$inc' => ["click_left." . $data['for'] => -1]
        ];

        $this->_product_seo_details_table->updateMany($def_search, $setArray);

        return ['success' => true, 'message' => 'History Updated', 'code' => 'success'];
    }
}
