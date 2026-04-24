<?php

namespace App\Core\Components;

use App\Core\Models\Resource;
use App\Core\Models\Acl\Role;
use Symfony\Component\Console\Output\ConsoleOutput;

class Setup extends Base
{

    private $output;


    public function _construct()
    {
        $this->output = new ConsoleOutput();
    }
    /**
     * Perform the upgrade
     *
     * @param bool $verbose, List all the modules while upgrading
     * @return void
     */
    public function upgradeAction(bool $verbose = false)
    {
        $this->upgradeSchema($verbose);
        echo "\e[33mThe modules has been upgraded." . PHP_EOL;
        echo "Updating resources." . PHP_EOL;
        $this->updateResourcesAction();
        echo 'Resources are updated .' . PHP_EOL;
        $this->upgradeAcl();
        try {
            echo 'Building Acl.' . PHP_EOL;
            $this->buildAclAction();
            echo 'Acl build completed.' . PHP_EOL;
            $this->di->getCache()->flushAll();
            echo "Cache has been Flushed. \e[0m" . PHP_EOL;
        } catch (\Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => 'error while building child acl'];
        }
        $this->di->getLog()->logContent("TEST", 'critical', 'success.log');
        return ['success' => true, 'message' => 'Upgrade successful'];
    }

    /**
     * UpgradeSchema, make database entries for acl
     *
     * @param boolean $verbose
     * @return void
     */
    public function upgradeSchema($verbose = false)
    {
        $this->setActiveModules();
        foreach ($this->getAllModules() as $mod) {
            foreach ($mod as $module) {
                if ($verbose) {
                    print_r($module);
                }
                if ($module['active']) {
                    $this->output->writeln(
                        "<options=bold;bg=bright-blue> ➤ Upgrading Module {$module['name']} </>"
                    );
                    $className = "App\\" .
                        ucfirst($module['name']) .
                        "\Setup\UpgradeSchema";

                    if (class_exists($className)) {
                        $this->di
                            ->getObjectManager()
                            ->get($className)
                            ->upgrade(
                                $this->di,
                                $module['name'],
                                $module['version']
                            );
                    }
                    $className = "App\\" . ucfirst($module['name']) . "\Setup\UpgradeData";
                    if (class_exists($className)) {
                        $this->di
                            ->getObjectManager()
                            ->get($className)
                            ->upgrade($this->di, $module['name'], $module['version']);
                    }
                    foreach ($this->di->get('config')->databases as $key => $value) {
                        if ($value->adapter == 'Mongo') {
                            continue;
                        }
                        // MySql
                        $connection = $this->di->get($key);
                        $dbVersion = $connection->query(
                            "Select version From setup_module where module = '{$module['name']}'"
                        )
                            ->fetch();
                        if (isset($dbVersion['version'])) {
                            if ($module['version'] > $dbVersion['version']) {
                                $connection->query(
                                    "Update setup_module set version = '{$module['version']}' where module = '{$module['name']}'"
                                );
                            }
                        } else {
                            $connection->query(
                                "Insert into setup_module (module, version) VALUES ('{$module['name']}', '{$module['version']}')"
                            );
                        }
                    }
                    $this->output->writeln(
                        "<options=bold;bg=#008000> ➤ Module {$module['name']} upgraded successfuly </>"
                    );
                    echo PHP_EOL;
                }
            }
        }
    }
    /**
     * Update acl_role_resource in database
     *
     * @return void
     */
    public function upgradeAcl()
    {
        try {
            $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $roleResources = $baseMongo->getCollection('acl_role_resource')->find()->toArray();

            //Adding customer_api role
            $this->createCustomRole('customer_api');
            if (count($roleResources) == 0) {
                $collection = $baseMongo->getCollection('acl_role');
                $role = $collection->findOne(['code' => 'app']);

                $collection = $baseMongo->getCollection('acl_resource');
                $aclResources = $collection->find([
                    'controller' => 'user',
                    'module' => 'core',
                    "action" => [
                        '$in' => [
                            "login", "forgot", "forgotreset", "create"
                        ]
                    ]
                ]);
                $aclRoleResource = $baseMongo->getCollection('acl_role_resource');
                foreach ($aclResources as $resource) {
                    $aclRoleResource->insertOne([
                        "role_id" => $role['_id'],
                        "resource_id" => $resource['_id']
                    ]);
                }
            }
        } catch (\Exception $e) {
            print_r($e->getMessage());
            return ["success" => false, "message" => 'Error occurred while building acl'];
        }
        return ['success' => true, 'message' => 'Acl Built'];
    }


    /**
     * Create a new custome role
     *
     * @param string $code
     * @param string $resources
     * @return void
     */
    public function createCustomRole($code, $resources = '')
    {
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $baseMongo->getCollection('acl_role');
        $role = $collection->findOne(['code' => $code]);

        if (!isset($role)) {
            try {
                $collection->insertOne([
                    'code' => $code,
                    'title' => $code,
                    'description' => $code,
                    'resources' => $resources,
                ]);
            } catch (\Exception $e) {
                print_r($e->getMessage());
                return ['success' => false, 'message' => 'Error occurred while creating ' . $code . ' role'];
            }
        }
    }



    /**
     * Upload resources to database and load them into memory 'require_once'
     *
     * @return void
     */
    public function updateResourcesAction()
    {
        $db = $this->di
            ->getObjectManager()
            ->get('App\Core\Models\BaseMongo')
            ->getConnection()
            ->acl_resource;
        // calculating the hash
        $hash = md5((string) $db->count());
        $finalResources = [];
        $resourcesToBeDeleted = [];
        $resourcesToBeInserted = [];
        foreach ($this->getAllModules() as $mod) {
            foreach ($mod as $module) {
                $visited = [];
                // fetching all controllers,actions of a current module
                $tmp = [];
                foreach ($db->find(
                    ['module' => $module['name']],
                    [
                        'array' => 'array',
                        'document' => 'array',
                        'root' => 'array',
                    ]
                ) as $doc) {
                    $tmp[$doc->module . '_' . $doc->controller . '_' . $doc->action] = 1;
                }
                if (file_exists(CODE . DS . $module['name'] . DS . 'controllers' . DS . 'BaseController.php')) {
                    require_once CODE . DS . $module['name'] . DS . 'controllers' . DS . 'BaseController.php';
                }
                foreach (glob(CODE . DS . $module['name'] . DS . 'controllers' . DS . '*.php') as $file) {
                    if ($file != CODE . DS . $module['name'] . DS . 'controllers' . DS . 'BaseController.php') {
                        require_once $file;
                    }
                    $fileName = explode(DS, $file);
                    $fileName = $fileName[count($fileName) - 1];
                    list($className, $fileExtension) = explode('.', $fileName);
                    if ($fileExtension != 'php') {
                        continue;
                    }
                    $moduleName = ucfirst($module['name']);
                    $class = "\App\\{$moduleName}\\Controllers\\{$className}";
                    $methods = get_class_methods($class);
                    $className = preg_replace('/(?<=\d)(?=[A-Za-z])|(?<=[A-Za-z])(?=\d)|(?<=[a-z])(?=[A-Z])/', '-', $className);
                    $className = strtolower(str_replace('-Controller', '', $className));
                    foreach ($methods as $method) {
                        if (strpos($method, 'Action') !== false) {
                            $hash .= md5($module['name'] . '_' . $className . '_' . $method);
                            $method = str_replace('Action', '', $method);
                            $resources = $module['name'] . '_' . $className . '_' . $method;
                            $finalResources[$module['name'] . '_' . $className . '_' . $method] = 1;
                            // if resource found , insert, and delete from tmp
                            if (isset($tmp[$resources])) {
                                $visited[$resources] = 1;
                            } else {
                                $resourcesToBeInserted[] = [
                                    'module' => $module['name'],
                                    'controller' => $className,
                                    'action' => $method
                                ];
                            }
                        }
                    }
                }
                $resourcesToBeDeleted = array_merge($resourcesToBeDeleted, array_diff_key($tmp, $visited));
            }
        }
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire(
            'resource:updateAfter',
            $this,
            [
                'hash' => &$hash,
                'db' => &$db,
                'resourcesToBeDeleted' => &$resourcesToBeDeleted,
                'resourcesToBeInserted' => &$resourcesToBeInserted
            ]
        );
        // checking in hash , if hash , skipping the resource upgrade
        if (
            is_file(BP . "/var/setup.hash") &&
            file_get_contents(BP . "/var/setup.hash") ===
            md5($hash)
        ) {
            return true;
        }
        $deleted = [];
        foreach ($resourcesToBeDeleted as $r => $_) {
            [$m, $c, $a] = explode('_', $r);
            $deleted[] = [
                'module' => $m,
                'controller' => $c,
                'action' => $a
            ];
        }
        if ($resourcesToBeInserted) $db->insertMany($resourcesToBeInserted);
        if ($deleted) $db->deleteMany(['$or' => $deleted]);
        // set calculated hash
        file_put_contents(BP . "/var/setup.hash", md5($hash));
    }
    /**
     * Build Acl from database
     *
     * @return void
     */
    public function buildAclAction($buildChildAcl = true)
    {
        $roles = Role::find();
        $acl = new \Phalcon\Acl\Adapter\Memory();
        $acl->setDefaultAction(\Phalcon\Acl\Enum::DENY);
        $resources = Resource::find()->toArray();
        $components = [];
        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource['_id'];
            $components[$resource['module'] . '_' . $resource['controller']][] = $resource['action'];
        }
        foreach ($components as $componentCode => $componentResources) {
            $acl->addComponent($componentCode, $componentResources);
        }
        foreach ($roles as $role) {
            $acl->addRole($role->code);
            if ($role->resources == 'all') {
                foreach ($components as $componentCode => $componentResources) {
                    $acl->allow($role->code, $componentCode, '*');
                }
            } else {
                foreach ($role->getAllResources() as $roleResource) {
                    $temp = array_search($roleResource->resource_id, $ids);
                    if ($temp !== false) {
                        $acl->allow(
                            $role->code,
                            $resources[$temp]['module'] . '_' . $resources[$temp]['controller'],
                            $resources[$temp]['action']
                        );
                    }
                }
            }
        }
        $this->di->getCache()->set('acl', $acl, 'setup');
        if ($buildChildAcl) {
            $this->buildChildAcl();
        }
        return true;
    }
    /**
     * Build acl for sub-users
     *
     * @return void
     */
    public function buildChildAcl()
    {
        $subUsers = \App\Core\Models\User\SubUser::find();
        $helper = $this->di->getObjectManager()->get(\App\Core\Components\Helper::class);
        foreach ($subUsers as $subuser) {
            $helper->generateChildAcl($subuser->toArray());
        }
    }
    public function buildChildAclById($id)
    {
        $subUser = \App\Core\Models\User\SubUser::find(['_id' => $id]);
        $helper = $this->di->getObjectManager()->get(\App\Core\Components\Helper::class);
        if (isset($subUser[0])) {
            $helper->generateChildAcl($subUser[0]->toArray());
        }
    }
    /**
     * Get status of all available modules
     *
     * @return void
     */
    public function statusAction()
    {
        $filePath = BP . DS . 'app' . DS . 'etc' . DS . 'modules.php';
        $fileData = [];
        if (file_exists($filePath)) {
            $fileData = require $filePath;
        }
        echo var_export($fileData) . PHP_EOL;
        return $fileData;
    }
    /**
     * Enable a module
     *
     * @param array $module - must contain module name at index 0
     * @return void
     */
    public function enableAction($module)
    {
        if (isset($module[0])) {
            $file = BP . DS . 'app' . DS . 'code' . DS . $module[0] . DS . 'module.php';
            if (file_exists($file)) {
                $fileData = require $file;
                if (isset($fileData['active']) && $fileData['active'] == 0) {
                    $fileData['active'] = 1;
                    $handle = fopen($file, 'w+');
                    fwrite($handle, '<?php return ' . var_export($fileData, true) . ';');
                    fclose($handle);
                    $this->setActiveModules();
                    echo 'The modules has been enabled.' . PHP_EOL;
                } else {
                    echo 'This module is already enabled.' . PHP_EOL;
                }
            } else {
                echo 'Mentioned module does not exists.' . PHP_EOL;
            }
        } else {
            echo 'You did not mentioned the module name to be enabled.' . PHP_EOL;
        }
    }
    /**
     * Disable a module
     *
     * @param array $module - must contain module name at index 0
     * @return void
     */
    public function disableAction($module)
    {
        if (isset($module[0])) {
            $file = BP . DS . 'app' . DS . 'code' . DS . $module[0] . DS . 'module.php';
            if (file_exists($file)) {
                $fileData = require $file;
                if (isset($fileData['active']) && $fileData['active'] == 1) {
                    $fileData['active'] = 0;
                    $handle = fopen($file, 'w+');
                    fwrite($handle, '<?php return ' . var_export($fileData, true) . ';');
                    fclose($handle);
                    $this->setActiveModules();
                    echo 'The modules has been disabled.' . PHP_EOL;
                } else {
                    echo 'This module is already disabled.' . PHP_EOL;
                }
            } else {
                echo 'Mentioned module does not exists.' . PHP_EOL;
            }
        } else {
            echo 'You did not mentioned the module name to be disabled.' . PHP_EOL;
        }
    }
    /**
     * Fetching all the modules
     * 
     * returns an array containing all the modules,
     * sorted with respect to their sort_order,
     * if missing then appended at the end.
     * 
     * Sub-directories of app/code that have a module.php file are being included.  
     *
     * @return array      *  
     */
    protected function getAllModules()
    {
        $modules = [];
        foreach (new \DirectoryIterator(CODE) as $fileInfo) {
            if (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $filePath = CODE . DS . $fileInfo->getFilename() . DS . 'module.php';
                if (file_exists($filePath)) {
                    $module = require $filePath;
                    if (isset($module['sort_order'])) {
                        $modules[$module['sort_order']][] = $module;
                    } else {
                        $modules[9999][] = $module;
                    }
                }
            }
        }
        ksort($modules, 1);
        return $modules;
    }
    /**
     * Locate active modules and write them to app/etc/modules.php 
     * 
     * To check if module is active, the module.php inside each module folder is checked. { @see Line 247 function getAllModules() } 
     * @return void
     */
    protected function setActiveModules()
    {
        $filePath = BP . DS . 'app' . DS . 'etc' . DS . 'modules.php';
        $activeModules = [];

        foreach ($this->getAllModules() as $mod) {
            foreach ($mod as $module) {
                $activeModules[$module['name']] = $module['active'];
            }
        }
        if (!file_exists(BP . DS . 'app' . DS . 'etc')) {
            $old = umask(0);
            mkdir(BP . DS . 'app' . DS . 'etc', 0777, true);
            umask($old);
        }

        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($activeModules, true) . ';');
        fclose($handle);
        return $activeModules;
    }
    /**
     * Clear notifications
     *
     * @return void
     */
    public function clearNotificationsAction()
    {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Core\Models\Notifications',
            'method' => 'clearSeenNotifications',
            'queue_name' => 'general',
            'own_weight' => 0,
            'data' => []
        ];
        if ($this->di->getConfig()->enable_rabbitmq_internal) {
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            $helper->createQueue($handlerData['queue_name'], $handlerData);
        }
        return true;
    }
}
