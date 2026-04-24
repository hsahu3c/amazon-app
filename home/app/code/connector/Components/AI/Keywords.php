<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;



class Keywords extends BaseMongo
{

    public $_target_marketplace;

    public $_target_id;

    public $_source_marketplace;

    public $_source_id;

    public $_user_id;


    public function init($data, $turnBufferingOff = false): void
    {
        if ($turnBufferingOff) {
            header('X-Accel-Buffering: no');
        }

        if (isset($data['user_id'])) {
            $this->_user_id = $data['user_id'];
        } else {
            $this->_user_id = $this->di->getUser()->id;
        }

        $this->_target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $this->_target_id = (string) ($data['target']['shopId']  ??  $this->di->getRequester()->getTargetId());

        $this->_source_marketplace =  $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();

        $this->_source_id = (string) ($data['source']['shopId']  ??  $this->di->getRequester()->getSourceId());
    }

    public function getKeywordsRelevantInfo($data)
    {
        if (!isset($data['keywords'])) {
            return ['success' => false, 'message' => 'Keywods info missing'];
        }

        if (!is_array($data['keywords'])) {
            return ['success' => false, 'message' => 'Keywods not array'];
        }

        $googleAdsApi = new \App\Connector\Api\GoogleAdsApi;

        $res = $googleAdsApi->getKeywordHistoricalMetrics($data);

        return ['success' => true, 'data' => $res, 'message' => 'Google Api Executed Successfully'];
    }

    public function getKeywordsSuggestions($data)
    {

        $intent = [];
        $awsLambda = new \App\Connector\Api\Helper\AWSLambda;
        $suggestionsObj = new \App\Connector\Components\AI\getSuggestions;
        $for = "keyword_generation";

        if (isset($data['intent']) && gettype($data['intent']) === 'array' && $data['intent'] !== []) {
            $intent = $data['intent'];
            $for = "intent_keywords";
        } else {
            $prompt = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\PromptHelper")->getPrompt('intent', $data);
            $res = $suggestionsObj->getChatRes($data, $prompt);
            $intent =  json_decode(($res['choices']['0']['message']['content']));
        }

        $awsLambdaUrl = 'https://s6gzgvxmh6pojdahcytdty7vjy0wtpke.lambda-url.us-east-2.on.aws/';

        $marketplaceID = $suggestionsObj->getMarketplaceID($this->di->getRequester()->getTargetId());
        $result = $awsLambda->invokeFunctionWithIAMCred($awsLambdaUrl, 'POST', [], [
            'marketplace_id' => $marketplaceID,
            'intent' => $intent
        ], ['region' => 'us-east-2']);

        $totalKeywords = [];
        foreach ($result as $v) {
            $suggestionList = $v['suggestions'] ?? [];
            if (!empty($suggestionList)) {
                foreach ($suggestionList as $val) {
                    $totalKeywords[] = $val['value'];
                }
            }
        }

        if (count($totalKeywords) == 0) {
            return ['success' => false, 'message' => 'No keywords found', 'intent' => $intent];
        }

        $suggestionsObj->updateGeneratedPrompt([
            'prompt' => "",
            'result' => json_encode(['keywords'=>join(",",$totalKeywords)]), 'source_product_id' => $data['source_product_id'],
            'for' => $for,
            'tone' => $data['tone']??""
        ]);
        return ['success' => true, 'message' => 'Keywords Found', 'data' => ['intent' => $intent, 'keywords' => $totalKeywords]];
    }
}
