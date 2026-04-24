<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class getSuggestions extends BaseMongo
{

    // TODO change in config
    public $_engine = 'gpt-3.5-turbo';

    // public $_engine = 'gpt-4';


    public $_max_token = 2500;

    public $_temperature = 0.7;

    public $_target_marketplace;

    public $_target_id;

    public $_source_marketplace;

    public $_source_id;

    public $_user_id;

    public $_default_marketplace_id = 'ATVPDKIKX0DER';

    public $_additional_history;


    public function init($data, $turnBufferingOff = false): void
    {
        // to reset

        if ($turnBufferingOff) {
            header('X-Accel-Buffering: no');
        }

        if (isset($data['user_id'])) {
            $this->_user_id = $data['user_id'];
        } else {
            $this->_user_id = $this->di->getUser()->id;
        }

        $this->_additional_history = isset($data['additional_history']) ? $data['additional_history'] : [];

        $this->_engine = isset($data['useEngine']) ? ($data['useEngine'] == 'gpt-4' ? 'gpt-4' : 'gpt-3.5-turbo') : 'gpt-3.5-turbo';

        $this->_target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $this->_target_id = (string) ($data['target']['shopId']  ??  $this->di->getRequester()->getTargetId());

        $this->_source_marketplace =  $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();

        $this->_source_id = (string) ($data['source']['shopId']  ??  $this->di->getRequester()->getSourceId());
    }

    public function checkIfCreditLeft($data)
    {
        $credits = new \App\Connector\Components\AI\credits;
        $res = $credits->updateCredits($data);
        return $res;
    }

    public function defaultPrompt()
    {
        return "Just provide result no additional info needed";
    }

    public function tonePromt($data)
    {
        if (!isset($data['tone']))
            return "";

        switch ($data['tone']) {
            case "Professional":
                $tone = $data['tone'] . ":" . "Create a" . $data['for'] . "that showcases the professionalism and quality of the product.";
                break;
            case "Conversational":
                $tone = $data['tone'] . ":" . "Craft a " . $data['for'] . " that engages customers in a friendly and approachable manner, as if having a conversation about the product.";
                break;
            case "Exciting":
                $tone = $data['tone'] . ":" . "Design a " . $data['for'] . " that evokes excitement and captures the attention of potential customers.";
                break;
            case "Authoritative":
                $tone = $data['tone'] . ":" . "Develop a " . $data['for'] . " that establishes the product as a trusted and authoritative choice in its category.";
                break;
            case "Humorous":
                $tone = $data['tone'] . ":" . "Come up with a " . $data['for'] . " that brings a touch of humor and light-heartedness to the product.";
                break;
            case "Luxurious/Elegant":
                $tone = $data['tone'] . ":" . "Devise a " . $data['for'] . " that exudes luxury, elegance, and sophistication, emphasizing the premium nature of the product.";
                break;
            case "Urgent/Sales Oriented":
                $tone = $data['tone'] . ":" . "Generate a " . $data['for'] . " that creates a sense of urgency and emphasizes the benefits of purchasing the product.";
                break;
            default:
                $tone = $data['tone'];
        }

        return " Tone of the result should be : {$tone}";
    }





    public function getCategoryPrompt($data)
    {
        $title = $data['title'];

        return "Suggest $this->_target_marketplace's category for the product with title: '{$title}'. Give result separated with '>' to maintain proper parent to child flow. No other additional info is needed";
    }

    public function getCategoryEmbeddedPromptAndInfo($data, $embeddingResult)
    {
        return ['idMapping' => $embeddingResult];
    }


    public function getBulletPointPrompt($data)
    {
        $title = $data['title'];
        $description = $data['description'];
        $bullet_point_word_limit =  $data['bullet_point_word_limit'] ?? 20;

        return "Here is product title: {$title} and description : {$description}. I want you to create bullet points on the basis of this that will imporve my visibility on $this->_target_marketplace marketplace. Limit each bullet point to {$bullet_point_word_limit} word limit. Tell only what is asked and no other info is needed" . $this->tonePromt($data) . $this->defaultPrompt();
    }

    public function getkeywordPrompt($data)
    {
        $title = $data['title'];
        if(isset($data['description'])){
            $data['description'] = strip_tags($data['description']);
            $data['description'] = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $data['description'])));
        }

        $description = $data['description'];

        $keyword_limit =  $data['keyword_limit'] ?? 10;

        return "Here is product title: {$title} and description : {$description}. I want you to give me keywords the basis of this that will imporve my visibility on $this->_target_marketplace marketplace.Keywords can not be sentance limit them to max 2 words .Limit keyword count to {$keyword_limit}. Prefer giving long-tail keywords in points. Just reply with keywords do not add extra sentances before telling keywords, this is not a conversation" . "Also, make each keywords are comma (,) seprated" . $this->tonePromt($data) . $this->defaultPrompt();
    }

    public function validation($data, $for = 'seo_suggestions')
    {
        if (empty($data['source_product_id']))
            return ['success' => false, 'message' => 'source_product_id missing', 'code' => 'data_missing'];

        if (($for == 'category_suggestions' || $for == 'generate_bullet_points')  && empty($data['title']))
            return ['success' => false, 'message' => 'Please provide relevant Information', 'code' => 'data_missing', 'data' => 'title or description missing'];

        if ($for == 'seo_suggestions' && (empty($data['for'])))
            return ['success' => false, 'message' => 'Please provide relevant Information', 'code' => 'data_missing', 'data' => 'for missing'];

        return $this->checkIfCreditLeft($data);
    }

    /**
     *
     * @param [array] $data
     * $data = ["prompt" => "", "result" => "", 'for'=> 'title' / 'description', 'source_product_id' => '12234455']
     * @return array
     */
    public function updateGeneratedPrompt($data)
    {
        $data['user_id'] = $this->_user_id;
        $data['additional_history'] = $this->_additional_history;
        $updateInfo = new \App\Connector\Components\AI\updateInfo;
        $res = $updateInfo->updateGeneratedPrompt($data);
        return $res;
    }


    /**
     *
     * @param empty
     * @return string
     */
    public function getMarketplaceID($target_id = null)
    {
        if ($target_id) {
            $this->_target_id = $target_id;
        }

        $user_details = $this->di->getUser()->toArray();
        if (isset($user_details['shops'])) {
            foreach ($user_details['shops'] as $value) {
                if ($value['_id'] == $this->_target_id) {
                    if (isset($value['warehouses'][0]['marketplace_id'])) {
                        return $value['warehouses'][0]['marketplace_id'];
                    }
                }
            }
        }

        return $this->_default_marketplace_id;
    }

    public function getEmbeddedResult($data)
    {
        $marketplaceID = $this->getMarketplaceID();

        $embeddingSearch  = new \App\Connector\Components\AI\embeddingSearch;

        $filter = $this->_target_marketplace == 'amazon' ? ['marketplace_id' => ['$eq' => $marketplaceID]] : [];

        return $embeddingSearch->fetch("Description: " . strip_tags($data['description']) . "Title : " . $data['title'], $filter, $this->_target_marketplace);
    }

    public function stopEventStream($res, $streamingOn =  true): void
    {
        if (!$streamingOn)
            return;

        header('Content-Type: text/event-stream');
        // Disable output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ob_implicit_flush(true);
        $decodedData = json_encode($res);
        echo "event: error\n";
        echo "data: {$decodedData}\n\n";
        flush();
    }


    /**
     *
     * @param [array] $data
     * $data = ['for' =>'title', 'info' => 'Cart product' , 'type'=> 'bullet_poin2wt' , 'suggestion_count' => 2, 'source_product_id'];
     * @return array
     */
    public function getSeoSuggestions($data)
    {

        $this->init($data, true);
        if(isset($data['description'])){
            $data['description'] = strip_tags($data['description']);
            $data['description'] = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $data['description'])));
        }

        $validation = $this->validation($data);
        if ($validation['success'] == false) {
            $this->stopEventStream($validation, $data['stream'] ?? true);
            return $validation;
        }

        $prompt = $this->di->getObjectManager()->get("\App\Connector\Components\AI\Prompts\PromptHelper")->getPrompt($data['for'], $data);
        $openAI = new \App\Connector\Api\OpenAI;

        $res = $openAI->chatCompletion([
            'model' => $this->_engine, // we can use gpt-4 and gpt3.5 only as chat completion
            'max_tokens' => $this->_max_token,
            'temperature' => $this->_temperature, // 0.7
            'messages' => $prompt, // need more details
            'stream' => isset($data['stream']) ? $data['stream'] :  false
        ]);

        // this updates history in db and manages credits
        $updateRes =  $this->updateGeneratedPrompt([
            'prompt' => $data['info'],
            'result' => $res['response_text'], 'source_product_id' => $data['source_product_id'],
            'for' => $data['for'],
            'tone' => $data['tone']
        ]);

        $res['update_response'] = $updateRes;
        // TODO proper retuen handle
        return ['success' => true, 'message' => 'Done', 'data' => $res];
    }



    /**
     *
     * @param [array] $data
     * $data = ['title' => 'axx' (required) , 'description' => 'sdfsf'(optional) , 'source_product_id'];
     * @return array
     */
    public function getCategorySuggestions($data)
    {
        $this->init($data);

        $data['for'] = 'category_suggestions';

        if ($this->_target_marketplace == 'amazon' || $this->_target_marketplace == 'etsy') {
            return $this->getCategorySuggestionsWithEmbedding($data);
        }

        $validation = $this->validation($data, 'category_suggestions');
        if ($validation['success'] == false) {
            return $validation;
        }

        $prompt = $this->getCategoryPrompt($data);

        $openAI = new \App\Connector\Api\OpenAI;

        $res = $openAI->chatCompletion(['model' => $this->_engine, 'max_tokens' => 500, 'temperature' => 0, 'messages' => [['role' => 'user', 'content' => $prompt]]]);

        $updateRes =  $this->updateGeneratedPrompt(['prompt' => ['title' => $data['title'], 'description' => $data['description'] ?? ''], 'result' => $res['response_text'], 'source_product_id' => $data['source_product_id'], 'for' => $data['for'], 'addtional_history' => $data['addtional_history']]);

        return ['choices' => $res['choices'][0]['message']['content'], 'update_response' => $updateRes];
    }


    /**
     *
     * @param [array] $data
     * $data = ['title' => 'axx' (required) , 'description' => 'sdfsf'(optional) , 'source_product_id'];
     * @return array
     */
    public function getCategorySuggestionsWithEmbedding($data)
    {
        $data['for'] = 'category_suggestions';

        $validation = $this->validation($data, 'category_suggestions');
        if ($validation['success'] == false) {
            return $validation;
        }

        $embeddingResult = $this->getEmbeddedResult($data);



        $returnOnlyFromEmbedding = true;

        $resEmbedded = $this->getCategoryEmbeddedPromptAndInfo($data, $embeddingResult);

        if ($returnOnlyFromEmbedding) {
            $updateRes =  $this->updateGeneratedPrompt(['prompt' => ['title' => $data['title'], 'description' => $data['description'] ?? ''], 'result' => $resEmbedded['idMapping'], 'source_product_id' => $data['source_product_id'], 'for' => $data['for']]);

            return ['success' => true, 'choices' =>  ['optimized_similar_result' => $resEmbedded['idMapping']], 'update_response' => $updateRes];
        }
    }



    /**
     *
     * @param [array] $data
     * $data = ['for' => 'generate_bullet_points,'title' => 'axx' (required) , 'description' => 'sdfsf'];
     * @return array
     */
    public function getBulletPointSuggestions($data)
    {
        $data['for'] = 'generate_bullet_points';

        $this->init($data, true);

        $validation = $this->validation($data, 'category_suggestions');
        if ($validation['success'] == false) {
            $this->stopEventStream($validation, $data['stream'] ?? true);
            return $validation;
        }

        $prompt = $this->getBulletPointPrompt($data);

        $openAI = new \App\Connector\Api\OpenAI;

        $res = $openAI->chatCompletion([
            'model' => $this->_engine,
            'max_tokens' => $this->_max_token,
            'temperature' => $this->_temperature,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Your Digital marketing tool that will help me in optimizing my products listings. You need to provide only result no other info is needed'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'stream' => isset($data['stream']) ? $data['stream'] :  true
        ]);

        $updateRes =  $this->updateGeneratedPrompt([
            'prompt' => ['title' => $data['title'], 'description' => $data['description'] ?? ''],
            'result' => $res['response_text'], 'source_product_id' => $data['source_product_id'], 'for' => $data['for'], 'tone' => $data['tone']
        ]);

        return ['choices' => $res, 'update_response' => $updateRes];
    }


    /**
     * description: get Image Attributes
     * @param [array] $data
     * $data = ['for' => 'image_attributes,'title' => 'axx' (required) , 'description' => 'sdfsf'];
     * @return array
     */
    public function getImageAttributes($data)
    {
        json_encode($data);
        new \App\Connector\Api\OpenAI;

        $vision = new \App\Connector\Api\Vision;

        //OBJECT_LOCALIZATION,LABEL_DETECTION,LOGO_DETECTION,TEXT_DETECTION,IMAGE_PROPERTIES (color),WEB_DETECTION ,OBJECT_LOCALIZATION
        $res = $vision->getImageAttributes([
            'requests' => [
                [
                    'image' => [
                        'source' => [
                            'imageUri' => 'https://m.media-amazon.com/images/I/5131bW8MJhL._UX679_.jpg'
                        ]
                    ],
                    'features' => [
                        [
                            'type' => 'LABEL_DETECTION'
                        ],
                        [
                            'type' => 'IMAGE_PROPERTIES'
                        ],
                        [
                            'type' => 'LOGO_DETECTION'
                        ],
                        [
                            'type' => 'OBJECT_LOCALIZATION'
                        ],
                        [
                            'type' => 'TEXT_DETECTION'
                        ],
                        [
                            'type' => 'WEB_DETECTION'
                        ]
                    ]
                ]
            ]
        ]);
        return $res;
        // die(json_encode($res));
    }



    public function getKeywords($data)
    {
        $data['for'] = 'keyword_generation';
        $this->init($data, true);

        $validation = $this->validation($data, 'generate_bullet_points');

        if ($validation['success'] == false) {
            $this->stopEventStream($validation, $data['stream'] ?? true);
            return $validation;
        }



        $prompt = $this->getkeywordPrompt($data);


        $openAI = new \App\Connector\Api\OpenAI;

        $res = $openAI->chatCompletion([
            'model' => $this->_engine,
            'max_tokens' => $this->_max_token,
            'temperature' => $this->_temperature,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Your Digital marketing tool that will help me in optimizing my products listings. You need to provide only result no other info is needed. Always reply in JSON format with key as "keywords" and value as array of keywords.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'stream' => isset($data['stream']) ? $data['stream'] :  true
        ]);

        $updateRes =  $this->updateGeneratedPrompt([
            'prompt' => ['title' => $data['title'], 'description' => $data['description'] ?? ''], 'result' => $res['response_text'], 'source_product_id' => $data['source_product_id'], 'for' => $data['for'], 'tone' => $data['tone']
        ]);

        return ['choices' => $res, 'update_response' => $updateRes];
    }

    public function getChatRes($data, $prompt)
    {
        $openAI = new \App\Connector\Api\OpenAI;
        $res = $openAI->chatCompletion([
            'model' => $this->_engine,
            'max_tokens' => $this->_max_token,
            'temperature' => $this->_temperature,
            'messages' => $prompt,
            'stream' => false
        ]);

        return $res;
    }
}
