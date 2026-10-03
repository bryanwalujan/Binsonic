<?php

// Alternative layout ONLY: copy to the domain's actual document root as index.php.
// Replace this absolute path. Keep application source, vendor and .env outside public_html.
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$appRoot = '/home/CPANEL_USER/apps/binsonic-terra';

if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $appRoot.'/vendor/autoload.php';
/** @var Application $app */
$app = require_once $appRoot.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
