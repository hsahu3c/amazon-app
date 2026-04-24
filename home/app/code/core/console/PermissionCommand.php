<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Give or revoke permissions to any role
 * options : -m (module),-c (controller),-a (action)
 * argument : revoke
 */
class PermissionCommand extends Command
{

    protected static $defaultName = "permission";
    protected static $defaultDescription = "gives permission to a resource";
    protected InputInterface $input;
    protected OutputInterface $output;
    private \Phalcon\Di\FactoryDefault $di;
    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct(\Phalcon\Di\FactoryDefault $di)
    {
        parent::__construct();
        $this->di = $di;
    }
    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Gives permission to any resource by role name');
        $this->addOption("module", "m", InputOption::VALUE_REQUIRED, "[Module Name]", "core");
        $this->addOption("role", "r", InputOption::VALUE_REQUIRED, "[Role type]", "anonymous");
        $this->addOption("controller", "c", InputOption::VALUE_REQUIRED, "[Controller Name]", "index");
        $this->addOption("action", "a", InputOption::VALUE_REQUIRED, "[Action name]", "*");
        $this->addArgument("revoke", InputArgument::OPTIONAL, "[Revoke name]");
    }
    /**
     * The main logic to execute when the command is run
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->di->getObjectManager()->get("App\Core\Components\Setup")->updateResourcesAction();
        $this->output = $output;
        $this->output->writeln("<options=bold;bg=red> ↭ </><options=bold;bg=bright-green> Version : 1.0.1 </>");
        $this->input = $input;
        $this->output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Updating Resources</>");
        $mongoConnection = $this->di->getObjectManager()
            ->get('\App\Core\Models\BaseMongo')->getConnection();
        $roleId = (string) $this->checkRole($input->getOption("role"), $mongoConnection);
        $this->checkResources(
            $input->getOption("module"),
            $input->getOption("controller"),
            $input->getOption("action"),
            $roleId,
            $mongoConnection
        );
        $this->di->getCache()->flushAll();
        $this->output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Cache Flushed</>");
        $this->di->getObjectManager()->get('App\Core\Components\Setup')->buildAclAction();
        $this->output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Acl File Rebuild</>");
        return 0;
    }
    public function checkRole($roleName, $connection)
    {
        $role = $connection->acl_role->findOne(['code' => $roleName]);
        if ($role) $this->output->writeln("<options=bold;bg=#1809eb> ➤ </><options=bold;fg=green> Role " . $roleName . " found</>");
        else {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;bg=red>Role " . $roleName . " not found </>");
            die;
        }
        return $role->_id;
    }
    public function checkResources($moduleName, $controllerName, $actions, $roleId, $connection)
    {
        // exit if module does't exist
        if (!$connection->acl_resource->findOne(['module' => $moduleName])) {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;bg=red>Module " . $moduleName . " not found </>");
            die;
        }
        // exit if controller does't exist
        if (!$connection->acl_resource->findOne(['controller' => $controllerName])) {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;bg=red>Controller " . $controllerName . " not found </>");
            die;
        }
        if ($actions === '*') {
            $actions = [];
            $actionsConn = $connection->acl_resource->find(['module' => $moduleName]);
            foreach ($actionsConn as $ac) {
                array_push($actions, $ac->action);
            }
        } else {
            $actions = explode(",", $actions);
            // if action does't exist
            foreach ($actions as $act) {
                if ($actions !== '*' && !$connection->acl_resource->findOne(['action' => $act])) {
                    $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;bg=red>Action " . $act . " not found </>");
                }
            }
        }
        foreach ($actions as $action) {
            $checkRecord = $connection->acl_resource->findOne(
                [
                    'module' => $moduleName,
                    'controller' => $controllerName,
                    'action' => $action,
                ]
            );
            if (!$checkRecord)
                continue;
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> " . ucfirst($moduleName) . " ⤳ " . ucfirst($controllerName) . " ⤳ " . ucfirst($action) . "</>");

            $this->givePermission((string) $roleId, (string) $checkRecord->_id, $connection);
        }
    }
    public function givePermission($roleId, $resourceId, $connection)
    {
        $permission = $connection->acl_role_resource;
        if ($this->input->getArgument("revoke")) {
            $x = $permission->deleteOne(
                [
                    'role_id' => new MongoDB\BSON\ObjectID($roleId),
                    'resource_id' => new MongoDB\BSON\ObjectID($resourceId),
                ]
            );
            if ($x->getDeletedCount())
                $this->output->writeln("<options=bold;bg=bright-white> ➤ </><options=bold;fg=bright-white> ⎩━━━╾</><bg=bright-white>Permission Revoked Successfully.</>");
            else
                $this->output->writeln("<options=bold;bg=bright-white> ➤ </><options=bold;fg=bright-white> ⎩━━━╾</><bg=bright-magenta>Permission not Found.</>");
            return true;
        }
        if ($permission->findOne(['role_id' => new MongoDB\BSON\ObjectID($roleId), 'resource_id' => new MongoDB\BSON\ObjectID($resourceId)])) {
            $this->output->writeln("<options=bold;bg=#f7860c> ➤ </><options=bold;fg=red> ⎩━━━╾</><bg=#f7860c>Permission already given</>");
        } else {
            $permission->insertOne(
                [
                    'role_id' => new MongoDB\BSON\ObjectID($roleId),
                    'resource_id' => new MongoDB\BSON\ObjectID($resourceId),
                ]
            );
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> ⎩━━━╾</><bg=green>Permission given</>");
        }
    }
}
