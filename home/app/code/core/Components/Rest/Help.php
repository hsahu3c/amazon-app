<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Rest\Base;

/**
 * Calling the respective functions from this class's respective component
 */
class Help extends Base
{
    public function ls()
    {
        $path = __DIR__;
        $commands = [];
        foreach (glob($path . "/*.php") as $file) {
            if (str_contains($file, "Base") || str_contains($file, "Help")) {
                continue;
            }
            $arr = explode("/", $file);
            $temp = end($arr);
            $tmp = str_replace(".php", "", $temp);
            $className = strtolower($tmp);
            $commands[$className] = [];
            $methods = (new \ReflectionClass("App\Core\Components\Rest\\" . ucwords($className)))->getMethods();
            $methodsArray = json_decode(json_encode($methods), true);
            foreach ($methodsArray as $k => $m) {
                if (method_exists('App\Core\Components\Rest\Base', $m['name'])) {
                    continue;
                }
                $commands[$className][$m['name']] = [];
                $params = $methods[$k]->getParameters();
                foreach ($params as $p) {
                    $commands[$className][$m['name']][] = $p->name;
                }
            }
        }
        return $commands;
    }
}
