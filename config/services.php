<?php

use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactory;
use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactoryInterface;
use FluffyDiscord\RapiraBundle\Worker\DispatcherInterface;
use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use FluffyDiscord\RapiraBundle\Worker\RapiraDispatcher;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(RapiraDispatcher::class)
    ;
    $services->alias(DispatcherInterface::class, RapiraDispatcher::class);

    $services
        ->set(SymfonyRequestFactory::class)
        ->args([
            '%kernel.project_dir%/public/index.php',
        ])
    ;
    $services->alias(SymfonyRequestFactoryInterface::class, SymfonyRequestFactory::class);

    $services
        ->set(HttpWorker::class)
        ->public()
        ->args([
            service(KernelInterface::class),
            service(EventDispatcherInterface::class),
            param('kernel.debug'),
            service(DispatcherInterface::class),
            service(SymfonyRequestFactoryInterface::class),
            service('services_resetter')->nullOnInvalid(),
            service(SentryHubInterface::class)->nullOnInvalid(),
        ])
    ;
};
