<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
define("TRACK_INIT", microtime(true));
$app = require '../app/bootstrap.php';
$app->run();
