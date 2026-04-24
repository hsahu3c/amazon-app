<?php

namespace App\Connector\Components\AI\Prompts;

use App\Core\Models\BaseMongo;

class PromptHelper extends BaseMongo
{

    public $_target_marketplace;

    public $_target_id;

    public $_source_marketplace;

    public $_source_id;

    public function init($data): void
    {
        $this->_target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $this->_target_id = (string) ($data['target']['shopId']  ??  $this->di->getRequester()->getTargetId());

        $this->_source_marketplace =  $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();

        $this->_source_id = (string) ($data['source']['shopId']  ??  $this->di->getRequester()->getSourceId());
    }



    /**
     * starting point
     */
    public function getPrompt($for, $data)
    {
        if (isset($data['primary_category'])) {
            if ($data['for'] === 'keywords' || $data['for'] === 'key_feature'||$data['for'] === 'single_bullet_point' || $data['for'] === 'tags' || $data['for'] === 'search_terms' || $data['for'] === 'subject_matter' ||$data['for'] === 'target_audience' || $data['for'] === 'intend_use') {
                return $this->getDefaultSeoPrompt($for, $data, $data['primary_category']);
            }

            return $this->getCategoryWisePrompt($for, $data);
        }

        return $this->getDefaultSeoPrompt($for, $data);
    }

    /**
     * categorywise prompts
     */
    public function getCategoryWisePrompt($for, $data)
    {

        $useKeyword = isset($data['useKeyword']) && $data['useKeyword'] ? true : false;
        $isParent = isset($data['isParent']) && $data['isParent'] ? true : false;
        $category = $data['primary_category'];

        $instructionHelper = new \App\Connector\Components\AI\Prompts\InstructionsBase;
        $categoryInfo = $instructionHelper->getInstruction($category, $for);
        if (!$categoryInfo) {
            return $this->getDefaultSeoPrompt($for, $data);
        }

        // print_r($categoryInfo);die;
        $format = $for === 'title' ? $isParent ? $categoryInfo['format']['parent'] : $categoryInfo['format']['child'] : $categoryInfo['format'];
        $instructions = $categoryInfo['instructions'] ?? "";
        $additionals = $categoryInfo['additionals'] ?? [];
        if ($for === 'title') {
            $sampleRequest = $categoryInfo['user_input'] ?? "";
            $sampleResponse = $categoryInfo['response'][$isParent ? "parent" : "child"] ?? "";
        }

        $systemContent = $this->initialPrompt($useKeyword) .
            " 
            Write a product {$for} based on the information provided by the user.

            acceptable response format: ```{$format} ```
            " .
            $instructions . $this->getDefaultAdditonalInstructions($data, $additionals, $for);

        // print_r($systemContent);die;
        $prompt = [
            [
                'role' => 'system',
                'content' => $systemContent
            ]
        ];
        if ($for === 'title') {
            $prompt[] = [
                'role' => 'user',
                'content' => $sampleRequest
            ];
            $prompt[] = [
                'role' => 'assistant',
                'content' => $sampleResponse
            ];
        }

        $prompt[] = [
            'role' => 'user',
            'content' => '{
                            title : ' . ($data['title'] ?? "") . ',
                            description : ' . (strip_tags($data['description']) ?? "") . ',
                            variant_attribute : ' . (isset($data['variant_attribute']) && $data['variant_attribute'] ? json_encode($data['variant_attribute']) : "{}") .
                ($useKeyword ? '
                    keywords : [' . ($data['keywords']  ?? "") : "") .
                ']
                        }'
        ];

        // die(json_encode($prompt[3]));
        return $prompt;
    }

    /**
     * Default SEO Prompt
     */
    public function getDefaultSeoPrompt($for, $data, $category = false)
    {
        $title = $data['title'];
        $description = $data['description'];
        $bullet_point_word_limit = 20;
        $word_count = isset($data['word_count']) ? $data['word_count'] : 1500;
        $keyword_limit =  $data['keyword_limit'] ?? 10;
        $prompt = [];
        $sentence = $data['key_feature'] ?? "";
        $keywords = $data['keywords']??"";
        if($for === "key_feature" && !strlen($sentence)){
            $for = "single_bullet_point";
        }

        switch ($for) {
            case "title":
                $prompt =  "You need to create title in the following format: keep the title '|' separated";
                break;
            case "description":
                $prompt = "Limit the max character count to {$word_count}";
                break;
            case "bullet_points":
                $prompt = " Limit each bullet point to {$bullet_point_word_limit} word limit.";
                break;
            case "single_bullet_point":
                $prompt = "Limit bullet point to {$bullet_point_word_limit} word limit.";
                break;
            case "keywords":
                $prompt = "Keywords can not be sentance limit them to max 2 words .Limit keyword count to {$keyword_limit}. Prefer giving long-tail keywords in points. Just reply with keywords do not add extra sentances before telling keywords, this is not a conversation" . "Also, make each keywords are comma (,) seprated";
            case "intent":
                $prompt = "I want you tell me what can be intent to search the product give result in json format which contains intent in one word or two words.Give top 10 intent. Just reply with array of intents. do not add any other additional info just reply in the format : [intent1, inten2,....]";
            case "tags":
                $prompt = "Provide tags that will increase SEO ranking of the product. tags must be of one word only. response format:`tag1, tag2, tag3 ....tagn`";
                break;
            case "search_terms":
                $prompt = "Provide probable search terms that user can use to search this product. example: black \n shoes .... and so on. each search term must be of one word. do not include competitor brand names or ASINs. response format:`searchTerm1 \n searchTerm2 \n searchTerm3 \n searchTerm4 \n searchTerm5 \n .... and so on.`";
                break;
            case "subject_matter":
                $prompt = "The Subject Matter Field comprises five text fields that you enter to make your listing look dashing. 
                You are allowed to enter fifty characters for every field, offering you enough space to describe your products. Write subject matters based on user input. response format:`subjectMatter1 \n subjectMatter2 \n subjectMatter3 \n subjectMatter4 \n subjectMatter5 \n .... and so on.`";
                break;
            case "target_audience":
                $prompt = "A target audience is a group of people defined by certain demographics and behavior. Provide target Audience based on title and description provided by user. target audiences must be of one word only. Provide atleast 5.
                example: `men \n boys \n unisex... and so on` . acceptable response format:`target audience1 \n target audience2 \n target audience3 \n target audience4 \n target audience5 \n .... and so on.`";
                break;
            case "intend_use":
                $prompt = "Intend Use specify what your item can be used for. Provide intend use based on title and description provided by user. Do not add anything extra just reply with result.
                response format:`abc \n def \n xyz \n adx and so on.` Do not add anything extra just reply with intend use only.";
                break;
            default:
            "";
        }


        return [
            [
                'role' => 'system',
                'content' => 'Your Digital marketing tool that will help me in optimizing my products listings. You need to provide only result no other info is needed'
            ],
            [
                'role' => 'user',
                'content' => $for === 'key_feature' ? "optimise this sentence in about 100 characters using the sentence and keyword provided, sentence:'{$sentence}', keyword:'{$keywords}' Tell only what is asked and no other info is needed" .  $this->defaultPrompt("sentence") : "Here is product title: ```{$title}``` ,description : ```{$description}```, and keywords:```{$keywords}```" . ($category ? "and category: ```{$category}```" : "") . ". I want you to create {$for} on the basis of this that will improve my visibility on $this->_target_marketplace marketplace. " .
                    $prompt . "Tell only what is asked and no other info is needed" 
                    . $this->tonePromt($data)
                     . $this->defaultPrompt($for)
            ]
        ];
    }

    public function defaultPrompt($for)
    {
        return "Just provide {$for} no additional info needed";
    }

    /**
     * necessary functions
     */

    /**
     * function to return tone
     */
    public function tonePromt($data)
    {
        if (!isset($data['tone']) || ($data['for'] === 'tags' || $data['for'] === 'search_terms' || $data['for'] === 'subject_matter' ||$data['for'] === 'target_audience' || $data['for'] === 'intend_use'))
            return "";

        switch (strtolower($data['tone'])) {
            case "professional":
                $tone = $data['tone'] . ":" . "Create a" . $data['for'] . "that showcases the professionalism and quality of the product.";
                break;
            case "conversational":
                $tone = $data['tone'] . ":" . "Craft a " . $data['for'] . " that engages customers in a friendly and approachable manner, as if having a conversation about the product.";
                break;
            case "exciting":
                $tone = $data['tone'] . ":" . "Design a " . $data['for'] . " that evokes excitement and captures the attention of potential customers.";
                break;
            case "authoritative":
                $tone = $data['tone'] . ":" . "Develop a " . $data['for'] . " that establishes the product as a trusted and authoritative choice in its category.";
                break;
            case "humorous":
                $tone = $data['tone'] . ":" . "Come up with a " . $data['for'] . " that brings a touch of humor and light-heartedness to the product.";
                break;
            case "luxurious/elegant":
                $tone = $data['tone'] . ":" . "Devise a " . $data['for'] . " that exudes luxury, elegance, and sophistication, emphasizing the premium nature of the product.";
                break;
            case "urgent/sales oriented":
                $tone = $data['tone'] . ":" . "Generate a " . $data['for'] . " that creates a sense of urgency and emphasizes the benefits of purchasing the product.";
                break;
            default:
                $tone = $data['tone'];
        }

        return " Tone of the result should be : {$tone}";
    }

    public function initialPrompt($useKeyword)
    {
        return "You are digital marketing tool that will help me to create a product listing on Amazon.

        User will give data in json format with keys ```title, description, variant_attribute," . ($useKeyword ? " keywords" : "") . "```";
    }


    public function getDefaultAdditonalInstructions($data, $additionals)
    {
        $instructions = $additionals;
        array_push($instructions, (count($instructions) + 1) . ") Tone should be " . $this->tonePromt($data));
        $userInstructions = isset($data['instructions']) && count($data['instructions']) > 0 ? $data['instructions'] : [];
        foreach ($userInstructions as $val) {
            array_push($instructions, (count($instructions) - 1) . ") {$val}");
        }

        return " Important instructions :
            ```" . join(" \n", $instructions) . "```";
    }
}
