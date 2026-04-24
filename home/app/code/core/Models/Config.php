<?php

namespace App\Core\Models;

class Config extends BaseMongo
{
    protected $table = 'core_config_data';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
    public function set($path, $value, $framework = 'global')
    {
        $config = Config::findFirst("framework='{$framework}' AND path='{$path}'");
        if ($config) {
            $config->setValue($value)->save();
        } else {
            $this->setPath($path)->setFramework($framework)->setValue($value)->save();
        }
    }
    public function get($path, $framework = 'global')
    {
        $config = Config::findFirst("framework='{$framework}' AND path='{$path}'");
        if ($config) {
            return $config->getValue();
        } else {
            return false;
        }
    }
    public function getAllConfig($framework)
    {
        if (!$framework) {
            $framework = 'global';
        }
        $data = [];
        $configBypath = Config::find(["group" => "path"]);
        foreach ($configBypath as $config_path) {
            $data[$config_path->path] = '';
        }

        $configs = Config::find(["framework='{$framework}'"]);
        foreach ($configs as $config) {
            $data[$config->path] = $config->value;
        }
        return ['success' => true, 'message' => 'Got All Config', 'data' => $data];
    }

    public function saveConfig($data)
    {
        if ($data && isset($data['settings'])) {
            if (!isset($data['framework'])) {
                $data['framework'] = 'global';
            }
            foreach ($data['settings'] as $path => $value) {
                $this->set($path, $value, $data['framework']);
            }
            return ['success' => true, 'message' => 'Config Saved Successfully', 'data' => []];
        } else {
            return ['success' => false, 'code' => 'invalid_data', 'message' => 'Invalid Data'];
        }
    }
}
