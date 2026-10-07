<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'dbal' => [
            'url' => '%env(DATABASE_URL)%',
            'idle_connection_ttl' => 0,
        ],
    ]);

    $container->extension('framework', [
        'session' => [
            'handler_id' => '%env(SESSION_DSN)%',
        ],
    ]);

    $container->extension('rapira', [
        'doctrine' => [
            'preconnect' => true,
        ],
    ]);
};
