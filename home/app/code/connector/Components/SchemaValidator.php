<?php

namespace App\Connector\Components;
class SchemaValidator {
    protected $schema;

    private array $errors;

    public function __construct($schema) {
        $this->schema = $schema;
        $this->errors = [];
    }

    public function validate(array $data) {

        foreach ($this->schema as $field => $rules) {
            $validator = $rules['validator'];
            if(!array_key_exists($field, $data) && strpos($validator, "required")) {
                $this->errors[$field] = "{$field} is required";
                return false;
            }

            if(!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];
            $valid = true;
            if (is_callable($validator)) {
                $valid = call_user_func($validator, $value);
            } else {
                $validators = explode('|', $validator);
                $valid = false;
                foreach ($validators as $validator) {
                    switch ($validator) {
                        case 'string':
                            if (is_string($value)) {
                                $valid = true;
                            }

                            break;
                        case 'array':
                            if (is_array($value)) {
                                $valid = true;
                            }

                            break;
                        case "number":
                            if (is_numeric($value)) {
                                $valid = true;
                            }

                            break;
                        case "boolean":
                            if (is_bool($value)) {
                                $valid = true;
                            }

                            break;
                        case "email":
                            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                $valid = true;
                            }

                            break;
                        default:
                            if (in_array($value, $validators)) {
                                $valid = true;
                            };
                    }

                    if(!$valid) {
                        $this->errors[$field] = $rules['message'];
                        return $valid;
                    }
                }
            }
        }

        return $valid;
    }

    public function getErrors(): array {
        return $this->errors;
    }
}