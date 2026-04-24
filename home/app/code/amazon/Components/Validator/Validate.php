<?php

namespace App\Amazon\Components\Validator;

use Opis\JsonSchema\{
    Validator, Helper
};
use Opis\JsonSchema\Errors\{
    ErrorFormatter,
    ValidationError,
};
use App\Core\Components\Base;

class Validate extends Base
{
    public const MAX_ERROR_COUNT = 20;

    public const BUCKET_NAME = 'amazon-json-attributes';

    public $_validatorSchemaArray = [];

    public function execute($data)
    {
        try {
           if (is_object($data)) {
            $data = json_decode((string) $data, true);
           }
            if(isset($data['filePath']) && file_exists($data['filePath'])) {
                $fileContents = file_get_contents($data['filePath']);
                
                if (is_string($fileContents)) {
                    $fileContents = json_decode($fileContents, true);
                }

                if(isset($fileContents['feed'], $fileContents['schema'])) {
                    // $schema = unserialize(gzuncompress(base64_decode($fileContents['schema'])));
                    // $schema = $cschema = json_decode($schema,true);
                    $schemaJson = unserialize(gzuncompress(base64_decode((string) $fileContents['schema'])));

                    $this->_validatorSchemaArray = json_decode((string) $schemaJson,true);
                    $productFeeds = $fileContents['feed'];
                    $errorArray = [];
                    unset($productFeeds['json_product_type']);
                    unset($productFeeds['language_tag']);
                    unset($productFeeds['marketplace_id']);
                    foreach($productFeeds as $sourceProductId => $inputData) {
                        $inputData = unserialize(base64_decode((string) $inputData));
                        unset($inputData['json_product_type']);
                        unset($inputData['sku']);
                        $inputData = Helper::toJSON($inputData);
                        // $schema = Helper::toJSON($schema) ?? [];
                        $validator = new Validator();
                        // Set max errors
                        $validator->setMaxErrors(20);
                        // $result = $validator->validate($inputData, $schema);
                        $result = $validator->validate($inputData, $schemaJson);
                        if (!$result->isValid()) {
                            $error = $result->error();
                            $formatter = new ErrorFormatter();

                            /*// $custom = function (ValidationError $error) use ($formatter, $cschema) {
                            $custom = function (ValidationError $error) use ($formatter) {
                                $err = $formatter->format($error, true);
                                $title = "";
                                foreach($err as $k => $v) {
                                    $d = explode('/', $k);
                                    if (isset($d[1]) && !empty($d[1])) {
                                        // $title = $cschema['properties'][$d[1]]['title']?? "";
                                        $title = $this->_validatorSchemaArray['properties'][$d[1]]['title']?? "";
                                    }
                                }
                                return [
                                    'path' =>  $formatter->formatErrorKey($error),
                                    'label' => $title,
                                    'message' => $formatter->formatErrorMessage($error),
                                ];
                            };*/
                            /*$custom = function (ValidationError $error) use ($formatter) {
                                $schema = $error->schema()->info();

                                if($error->keyword() === 'enum') {
                                    return [
                                        'path' =>  implode('/', $error->data()->fullPath()),
                                        'label' => $this->getAttributeName($schema, $error),
                                        'default_message' => $formatter->formatErrorMessage($error),
                                        'message' => '"' . $error->data()->value() . '" is not from the allowed set of values : ' . implode(', ', $schema->data()->enumNames)
                                    ];
                                }
                                elseif($error->keyword() === 'type') {
                                    return [
                                        'path' =>  implode('/', $error->data()->fullPath()),
                                        'label' => $this->getAttributeName($schema, $error),
                                        'default_message' => $formatter->formatErrorMessage($error),
                                        'message' => 'Data type of "' . $error->data()->value() . '" is "'.$error->args()['type'].'", but "'.$error->args()['expected'].'" is expected'
                                    ];
                                }
                                elseif($error->keyword() === 'format') {
                                    $return = [
                                        'path' =>  implode('/', $error->data()->fullPath()),
                                        'label' => $this->getAttributeName($schema, $error),
                                        'message' => $formatter->formatErrorMessage($error),
                                        // 'custom_message' => 'Data type of "' . $error->data()->value() . '" is "'.$error->args()['type'].'", but "'.$error->args()['expected'].'" is expected'
                                    ];

                                    $expectedType = $error->args()['format'] ?? '';
                                    switch($expectedType) {
                                        case 'date-time':
                                        case 'date':
                                            $attrSchema = $this->getLeafAttributeSchema($error);
                                            $return['default_message'] = $return['message'];
                                            $return['message'] = "Value '{$error->data()->value()}' must be in '{$expectedType}' format. For Example '". current($attrSchema['examples']) ."'";
                                            break;
                                    }

                                    return $return;
                                }
                                else {
                                    return [
                                        'path' =>  implode('/', $error->data()->fullPath()),
                                        'label' => $this->getAttributeName($schema, $error, $schemaJson),
                                        'message' => $formatter->formatErrorMessage($error)
                                    ];
                                }
                            };*/
                            $custom = function (ValidationError $error) use ($formatter): array {
                                // print_r($error);die;
                                $schema = $error->schema()->info();

                                // var_dump($error->keyword());print_r($schema);die('validation ka schema');                    
                                // print_r($schema->data());die;
                                $errorDetails = $this->getErrorDetails($formatter, $error);

                                switch ($error->keyword()) {
                                    case 'enum':
                                        // $errorDetails['message'] = '"' . $error->data()->value() . '" is not from the allowed set of values : ' . implode(', ', $schema->data()->enumNames);
                                        if(property_exists($schema->data(), 'enumNames')) {
                                            $names = $schema->data()->enumNames;
                                        } else {
                                            $names = $this->getEnumNameFromEnum($errorDetails['path'], $schema->data()->enum);
                                        }

                                        if($names) {
                                            $errorDetails['message'] = '"' . $error->data()->value() . '" is not from the allowed set of values : ' . implode(', ', $names);
                                        } else {
                                            $errorDetails['message'] = '"' . $error->data()->value() . '" is not from the allowed set of values : ' . implode(', ', $schema->data()->enum);
                                        }

                                        break;

                                    case 'type':
                                        // $errorDetails['message'] = 'Data type of "' . $error->data()->value() . '" is "'.$error->args()['type'].'", but "'.$error->args()['expected'].'" is expected';
                                        $errorDetails['message'] = 'Data type of "' . print_r($error->data()->value(),true) . '" is "'.$error->args()['type'].'", but Amazon wants it in "'.$error->args()['expected'].'" format.';
                                        // {value} of List price is of type String but Amazon wants it in number format. So please provide number value
                                        break;

                                    case 'format':
                                        $expectedType = $error->args()['format'] ?? '';
                                        switch($expectedType) {
                                            case 'date-time':
                                            case 'date':
                                                $attrSchema = $this->getLeafAttributeSchema($error);
                                                $errorDetails['message'] = "Value '{$error->data()->value()}' must be in '{$expectedType}' format. For Example '". current($attrSchema['examples']) ."'";
                                                break;
                                        }

                                        break;

                                    case 'not':
                                        if(property_exists($schema->data(), 'not') && property_exists($schema->data()->not, 'required')) {
                                            $names = $this->getPropertyNameFromPath($errorDetails['path'], $schema->data()->not->required);
                                            if(in_array("maximum_retail_price",array_keys($names))){
                                                $errorDetails['message'] = 'Following properties to be Skipped';
                                            }
                                            elseif($names) {
                                                $errorDetails['message'] = 'Following properties are not allowed : ' . implode(', ', $names);
                                            } else {
                                                $errorDetails['message'] = 'Following properties are not allowed : ' . implode(', ', $schema->data()->not->required);
                                            }

                                        }

                                        break;

                                    default:
                                        // return [
                                        //     'path' =>  implode('/', $error->data()->fullPath()),
                                        //     'label' => $this->getAttributeName($schema, $error),
                                        //     'message' => $formatter->formatErrorMessage($error)
                                        // ];
                                        break;
                                }

                                return $errorDetails;
                            };

                            
                            if(!isset($formatter->formatKeyed($error,$custom)['/purchasable_offer/0'][0]['message'])){
                                $errorArray[$sourceProductId] = $formatter->formatKeyed($error, $custom);
                             } elseif (isset($formatter->formatKeyed($error,$custom)['/purchasable_offer/0'][0]['message']) && $formatter->formatKeyed($error,$custom)['/purchasable_offer/0'][0]['message'] != "Following properties to be Skipped") {
                                 $errorArray[$sourceProductId] = $formatter->formatKeyed($error, $custom);
                             }
                            // $error = $result->error();
                            // $formatter = new ErrorFormatter();
                            // $formattedError = $formatter->format($error, true);
                            // $errorArray[$sourceProductId] = $formattedError;
                        }
                    }

                    return [
                        'success' => true,
                        'code' => 200,
                        'errors' => $errorArray
                    ];
                }

                return [
                    'success' => false,
                    'data' => $data,
                    'error' => 'Required fields(feed, schema) missing in Feed file.'
                ];
            }

            return [
                'success' => false,
                'data' => $data,
                'error' => 'Required parameters:(filePath) missing or not valid.'
            ];
        } catch(Exception $e) {
            return [
                'code' => 500,
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // public function getAttributeName($schema, $error)
    // {
    //     if($schema->path()[0] === 'properties' && property_exists($schema->data(), 'title')) {
    //         return $schema->data()->title;
    //     }
    //     else {
    //         $pathArr = $error->data()->fullPath();

    //         if(!empty($pathArr[0])) {
    //             return $this->_validatorSchemaArray['properties'][$pathArr[0]]['title'] ?? '';
    //         } else {
    //             return '';
    //         }

    //     }
    // }

    // public function getAttributeSchema($error)
    // {
    //     $pathArr = $error->data()->fullPath();

    //     if(!empty($pathArr[0])) {
    //         return $this->_validatorSchemaArray['properties'][$pathArr[0]] ?? [];
    //     } else {
    //         return [];
    //     }
    // }

    public function getLeafAttributeSchema($error)
    {
        $fullPath = $error->data()->fullPath();
        $leafNode = end($fullPath);
        $pathArr = $error->schema()->info()->path();
        if(count($pathArr) > 0) {
            $schema = $this->_validatorSchemaArray;
            foreach($pathArr as $key) {
                $schema = $schema[$key];
                if($leafNode === $key) {
                    return $schema;
                    // break;
                }
            }
        }

        return [];
    }

    public function getErrorDetails($formatter, $error): array
    {
        $err = $formatter->format($error, true);
        $title = '';
        foreach($err as $k => $v) {
            $exp = explode('/', (string) $k);
            if (isset($exp[1]) && !empty($exp[1])) {
                $title = $this->_validatorSchemaArray['properties'][$exp[1]]['title']?? "";
            }
        }

        return [
            'path' =>  $formatter->formatErrorKey($error),
            'label' => $title,
            'default_message' => $formatter->formatErrorMessage($error),
            'message' => $formatter->formatErrorMessage($error),
        ];
    }

    public function getEnumNameFromEnum($attrPath, $enum): mixed
    {
        // var_dump($attrPath, $enum);die;
        $attrPathArr = [];
        if(!empty($attrPath) && $attrPath !== '/') {
            $attrPathArr = explode('/', (string) $attrPath);
        }

        $schema = $this->_validatorSchemaArray;
        foreach ($attrPathArr as $path) {
            if($path !== '') {
                if(is_numeric($path) && $schema['type'] == 'array') {
                    $schema = $schema['items'];
                }
                elseif(!is_numeric($path) && $schema['type'] == 'object') {
                    $schema = $schema['properties'][$path];
                }
            }
        }

        if(isset($schema['enum'], $schema['enumNames'])) {
            $enumNames = [];
            foreach($enum as $_enum) {
                $index = array_search($_enum, $schema['enum']);
                if(isset($schema['enumNames'][$index])) {
                    $enumNames[] = $schema['enumNames'][$index];
                }
            }
        }

        return $enumNames ?? false;
    }

    public function getPropertyNameFromPath($attrPath, $attrCodeArr)
    {
        $attrPathArr = [];
        if(!empty($attrPath) && $attrPath !== '/') {
            $attrPathArr = explode('/', (string) $attrPath);
        }

        $schema = $this->_validatorSchemaArray;
        foreach ($attrPathArr as $path) {
            if($path !== '') {
                if(is_numeric($path) && $schema['type'] == 'array') {
                    $schema = $schema['items'];
                }
                elseif(!is_numeric($path) && $schema['type'] == 'object') {
                    $schema = $schema['properties'][$path];
                }
            }
        }


        $names = [];
        foreach($attrCodeArr as $attrCode) {
            if(isset($schema['properties'][$attrCode]['title'])) {
                $names[$attrCode] = $schema['properties'][$attrCode]['title'];
            }
        }

        return $names ?? false;
    }
}
