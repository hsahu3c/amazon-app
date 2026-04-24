<?php

use Phalcon\Di\Di;
use \Phalcon\Di\FactoryDefault,
    Phalcon\Autoload\Loader;

$di = new FactoryDefault();
define('BP', dirname(__DIR__));
define('DS', '/');
define('CODE', BP . DS . 'app' . DS . 'code');
define('VAR', BP . DS . 'var');
define('HOST', 'https://apps.cedcommerce.com');
require BP . DS . 'vendor' . DS . 'autoload.php';

/**Register loader for modules**/
$di->set(
    'loader',
    function () {
        $loader = new Loader();
        return $loader;
    }
);
$di->set('isDev', function () {
    return isset($_SERVER['CED_IS_DEV']) ?
        (int)$_SERVER['CED_IS_DEV'] : (file_exists(BP . '/var/is-dev.flag') ?
            trim(file_get_contents(BP . '/var/is-dev.flag')) :
            0
        );
});
/** @var Loader */
$loader = $di['loader'];


$loader->setNamespaces(
    [
        'Phalcon' => BP . DS . 'vendor' . DS . 'phalcon' . DS . 'incubator' . DS . 'Phalcon' . DS,
        'App\Core'   => CODE . DS . 'core',
        'App\Core\Middlewares'   => CODE . DS . 'core' . DS . 'Middlewares' . DS,
    ]
);

$loader->register();

// Create an application
$application = new \App\Core\UnitApplication($di);




// Add any needed services to the DI here

Di::setDefault($application->getDi());
