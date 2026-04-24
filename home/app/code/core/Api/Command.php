<?php

namespace App\Core\Api;

class Command extends \Phalcon\Di\Injectable
{
    public function execute()
    {
        $response = [];
        try {
            $data = $this->request->getJsonRawBody(true);
            $component = $this->di->getObjectManager()
                ->get("App\Core\Components\Rest\\" . ucwords($data['command']), [$this->di]);
            $action = $data['subcommand'];
            if ($component) {
                if (method_exists($component, $action)) {
                    $params = $this->parseMethodNames((new \ReflectionMethod($component, $action))
                        ->getParameters()) ?? [];
                    if (count($params) > count($data['params'])) {
                        $response['success'] = false;
                        $response['message'] = "Invalid number of parameters";
                        $response['expected_parameters'] = $params;
                    } else {
                        $result = call_user_func_array([$component, $action], $data['params']);
                        return [
                            'output' => [
                                $result
                            ]
                        ];
                    }
                } else {
                    $response['success'] = false;
                    $response['message'] = "Method not found";
                }
            } else {
                $response['success'] = false;
                $response['message'] = 'component not found';
            }
            $response['component'] = $data['command'];
            $response['method'] = $action;
            return $response;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent($e->getMessage(), 'critical', 'exception.log');
            return ['success' => false, 'message' => 'some exception occurred'];
        }
    }
    /**
     * Format method names in a more compact arrangement
     *
     * @param [type] $arr
     * @return void
     */
    public function parseMethodNames($arr)
    {
        $ret = [];
        foreach ((array)$arr as $i) {
            $ret[] = $i->name ?? false;
        }
        return $ret;
    }
}
