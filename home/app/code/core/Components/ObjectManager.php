<?php

namespace App\Core\Components;

use ReflectionUnionType;

class ObjectManager extends Base
{

    const ERROR_MSG = 'Looks like something went wrong. Kindly Contact Support for Help.';

    public function create($type, array $args = [], $get = false, $tag = 'default')
    {
        $diType = $this->getDiClass($type);
        if (is_object($diType)) {
            $diType = $diType->get($tag) ? $diType->get($tag) : $diType->get('default');
        }
        if (is_null($diType)) {
            throw new \Exception('Class not found.');
        }
        if (isset($args[0]) && is_array($args[0]) && isset($args[0]['type'])) {
            $arguments = [];
            foreach ($args as $argument) {
                if ($argument['type'] == 'parameter') {
                    $arguments[] = $argument['value'];
                }
            }
        } else {
            $arguments = $args;
        }

        if (!empty($arguments)) {
            if (class_exists($diType)) {
                $wrapperObj = $this->generateWrapperClass($diType);
                if ($wrapperObj['success']) {
                    $reflection = new \ReflectionClass($wrapperObj['wrapperClass']);
                    return $reflection->newInstanceArgs($arguments);
                } else {
                    throw new \Exception(isset($wrapperObj['msg']) ?? self::ERROR_MSG);
                }
            } else {
                throw new \Exception('class not found.');
            }
        } else {
            $wrapperObj = $this->generateWrapperClass($diType);
            if ($wrapperObj['success']) {
                $reflection = new \ReflectionClass($wrapperObj['wrapperClass']);
                if (isset($wrapperObj['constructParam']))
                    $instance = $reflection->newInstanceArgs($wrapperObj['constructParam']);
                else {
                    $instance = new $wrapperObj['wrapperClass'];
                }
                $instance->setDi($this->di);
                return $instance;
            } else {
                throw new \Exception(isset($wrapperObj['msg']) ?? self::ERROR_MSG);
            }
        }
    }

    public function get($type, array $arguments = [], $tag = 'default')
    {
        if (isset($this->di[$type . '-' . $tag])) {
            return $this->di->get($type . '-' . $tag);
        } else {
            $diClass = $this->getDiClass($type);
            if (is_object($diClass)) {
                $diClass = $diClass->get($tag) ? $diClass->get($tag) : $diClass->get('default');
            }
            if (is_null($diClass)) throw new \Exception('Class not found.');

            $wrapperObj =  $this->generateWrapperClass($diClass);
            if (empty($arguments)) {
                if ($wrapperObj['success']) {
                    $reflection = new \ReflectionClass($wrapperObj['wrapperClass']);
                    if (isset($wrapperObj['constructParam'])) {
                        $reflection = $reflection->newInstanceArgs($wrapperObj['constructParam']);
                    } else $reflection = new $wrapperObj['wrapperClass'];
                } else {

                    throw new \Exception((isset($wrapperObj['msg'])) ? $wrapperObj['msg'] : self::ERROR_MSG);
                }
                // $instance = $this->di->get($diClass);
            } else {
                //$this->di->get($diClass);
                // $this->di->set($type, ['className' => $diClass,'arguments'=> $arguments
                //     ]);
                // $instance = $this->di->get($diClass);
                if ($wrapperObj['success']) {
                    $reflection = new \ReflectionClass($wrapperObj['wrapperClass']);
                    $reflection = $reflection->newInstanceArgs($arguments);

                    $this->di->set($wrapperObj['wrapperClass'], [
                        'className' => $wrapperObj['wrapperClass'], 'arguments' => $arguments
                    ]);
                } else {
                    throw new \Exception(isset($wrapperObj['msg']) ? $wrapperObj['msg'] : self::ERROR_MSG);
                }
            }
            $this->di->setShared($type . '-' . $tag, $reflection);
            // temporary fix @todo for Pankaj
            $this->di->setShared($type, $reflection);
            $reflection->setDi($this->di);
            return $reflection;
        }
    }

    // to_do : turn to anonymous function if feasible
    public function getScopeType($methodObj, $validScopes): string
    {
        $scope = '';
        foreach ($validScopes as $value) {
            switch ($value) {
                case 'final':
                    if ($methodObj->isFinal()) {
                        $scope = $value;
                    }
                    break;

                case 'private':
                    if ($methodObj->isPrivate()) {
                        $scope = $value;
                    }
                    break;

                case 'protected':
                    if ($methodObj->isProtected()) {
                        $scope = $value;
                    }
                    break;

                case 'public':
                    if ($methodObj->isPublic()) {
                        $scope = $value;
                    }
                    break;

                case 'static':
                    if ($methodObj->isStatic()) {
                        $scope = $value;
                    }
                    break;

                default:
                    break;
            }
            if ($scope != '') break;
        }
        return $scope;
    }

    public function generateWrapperClass($diClass)
    {
        // @to_do : modify below code to include interface also
        if (interface_exists($diClass)) {
            return [
                'success' => 0,
                'msg' => "Can't create class of interface."
            ];
        }
        try {
            $sec = new \ReflectionClass($diClass);
        } catch (\Exception $e) {
            return [
                'success' => 0,
                'msg' => $e->getMessage()
            ];
        }

        $classN = explode('\\', $sec->getName());
        if ($classN[0] == 'Phalcon') {
            array_pop($classN);
        } else {
            $classN = array_slice($classN, 1, -1);
        }
        $classNamespace = implode('\\', $classN);
        $class = '\\' . $sec->getName();
        $className = $sec->getShortName();
        $wrapperClassName = '\Generation\Wrapper\\' . $classNamespace . '\\' . $className;

        $classData = "<?php \nnamespace Generation\Wrapper\\" .
            $classNamespace . ";\n\nclass " .
            $className . " extends " .
            $class . "\n{\n ";
        $constructFlag = 0;
        $diFlag = 0; // temp properties

        if (
            ($sec->getConstructor() != null)
            && ('\\' . $sec->getConstructor()->getDeclaringClass()->getName() == $class)
        ) {
            $constructParamObjs = [];
            $shapedDataObj = $this->shapeParams($class, $sec, 'construct');
            if (!$shapedDataObj['success']) {
                return $shapedDataObj;
            }
            $classData .= $shapedDataObj['param'];
            $constructParamObjs = $shapedDataObj['obj'];
            $constructFlag = 1;
        }

        if (
            (count($sec->getMethods()) == 1) &&
            ($constructFlag == 1) ||
            (count($sec->getMethods()) == 0)
        ) {
            $classData .= '}';
        } else {
            foreach ($sec->getMethods() as $method) {
                if ($method->getName() == '__construct') {
                    continue;
                }
                // @todo modify the below code to support all methods
                if ($method->getName() == 'setDi') {
                    $shapedDataObj = $this->shapeParams($class, $method);
                    if (!$shapedDataObj['success']) return $shapedDataObj;
                    $diFlag = 1;
                    $classData .= $shapedDataObj['param'] . "\n";
                }
            }
        }
        if (!$diFlag) {
            $classData .= "\tpublic \$di;\n\tpublic function setDi(" .
                '\Phalcon\Di\DiInterface $di) :void {' .
                "\n\t\t" . '$this->di = $di' . "; \n\t}\n";
        }
        $classData .= "}";

        if (!file_exists(BP . DS . 'generation/' .
            str_replace('\\', '/', $classNamespace) . '//' .
            $className . '.php')) {
            $rootFilePath = BP . DS . 'generation/' .
                str_replace('\\', '/', $classNamespace) .
                '//' . $className . '.php';
            $dirname = dirname($rootFilePath);
            if (!is_dir($dirname)) {
                mkdir($dirname, 0775, true);
            }
            file_put_contents($rootFilePath, $classData);
        }

        if ($constructFlag == 1) {
            $returnObj = [
                'success' => 1,
                'wrapperClass' => $wrapperClassName,
                'constructParam' => $constructParamObjs
            ];
        } else {
            $returnObj = [
                'success' => 1,
                'wrapperClass' => $wrapperClassName
            ];
        }
        return $returnObj;
    }


    public function shapeParams($className, $dataObj, $type = 'method')
    {
        $paramString = '';

        if ($type == 'construct') {
            $validScopes = ['public', 'protected', 'private', 'final', 'static'];
            $scope = $this->getScopeType($dataObj->getConstructor(), $validScopes);
            $paramString .= "\t" . $scope . " function __construct(";
            $constructParam = '';
            $constructObj = [];
            $consVariable = '';
            if (!empty($dataObj->getConstructor()->getParameters())) {
                $count = 1;
                foreach ($dataObj->getConstructor()->getParameters() as $param) {
                    $params = new \ReflectionParameter([$className, '__construct'], ($count - 1));
                    if (!is_null($params->getType())) {
                        $isBuiltIn = $params->getType() instanceof ReflectionUnionType || $params->getType()->isBuiltin();
                        $constructParam .= ($isBuiltIn ? '' : '\\') . $params->getType() . ' ';

                        try {
                            $constructObj[] = $isBuiltIn ? null : $this->di->getObjectManager()->get((string)$params->getType());
                        } catch (\Exception $e) {
                            return [
                                'success' => 0,
                                'msg' => $e->getMessage() . ' Class not Found.'
                            ];
                        }
                    }

                    if (count($dataObj->getConstructor()->getParameters()) == $count) {
                        $defaultValue = $param->isOptional() ? (' = ' . var_export($param->getDefaultValue(), true)) : '';
                        $constructParam .= '$' . $param->getName() . $defaultValue;
                        $consVariable .=  '$' . $param->getName();
                    } else {
                        $constructParam .= '$' . $param->getName() . ', ';
                        $consVariable .= '$' . $param->getName() . ', ';
                    }
                    $count++;
                }
            }
            $paramString .= $constructParam . " ){\n\t\tparent::__construct(" . $consVariable . ");\n\t}\n";
            $returnData =  [
                'success' => 1,
                'param' => $paramString,
                'obj' => $constructObj
            ];
        } else {
            $validScopes = ['public', 'protected', 'private', 'final', 'static'];
            $scope = $this->getScopeType($dataObj, $validScopes);
            $paramString .= "\n\t" . $scope . ' function ' . $dataObj->getName() . "( ";
            $count = 1;

            foreach ($dataObj->getParameters() as $param) {
                $params = new \ReflectionParameter([$className, $dataObj->getName()], ($count - 1));
                if (!is_null($params->getType())) {
                    $paramString .= '\\' . $params->getType() . ' ';
                }

                if (count($dataObj->getParameters()) == $count) {
                    $paramString .= '$' . $param->getName();
                } else {
                    $paramString .= '$' . $param->getName() . ', ';
                }
                $count++;
            }
            $paramString .= ') : void {' . "\n\t\t" . ' parent::setDi($di); ' . "\n\t" . '}';

            $returnData =  [
                'success' => 1,
                'param' => $paramString
            ];
        }
        return $returnData;
    }


    public function getDiClass($key)
    {
        $config = $this->getDi()->get('config');
        $diType = $config->get('di')->get($key);
        $diType = is_null($diType) ? $key : $diType;
        return $diType;
    }
}
