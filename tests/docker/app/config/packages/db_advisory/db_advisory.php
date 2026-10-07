<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return static function (ContainerConfigurator $container): void {
    $container->import('../db/db.php');

    $container->services()
        ->set('app.advisory_session_handler', PdoSessionHandler::class)
        ->args(['%env(SESSION_DSN)%', ['lock_mode' => PdoSessionHandler::LOCK_ADVISORY]]);

    $container->extension('framework', [
        'session' => [
            'handler_id' => 'app.advisory_session_handler',
        ],
    ]);
};
