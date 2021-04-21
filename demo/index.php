<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/model/User.php';
require_once __DIR__ . '/Application.php';

use Atk4\Container\AppContainer;

try {
    $container = new AppContainer();
    $container->readConfig(__DIR__ . '/teams.config.php');

    $app = new Application([
        'title' => 'Teams demo',
        'container' => $container,
        'call_exit' => false,
        'always_run' => false,
        'url_building_ext' => '',
    ]);

    $app->run();
} catch (ErrorException $e) {
    // Initialization errors
    echo $e->getMessage();
}
