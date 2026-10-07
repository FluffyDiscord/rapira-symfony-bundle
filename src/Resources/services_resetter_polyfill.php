<?php

$isInterfaceInDependencyInjection = interface_exists(\Symfony\Component\DependencyInjection\ServicesResetterInterface::class);
if (!$isInterfaceInDependencyInjection) {
    class_alias(
        \Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface::class,
        \Symfony\Component\DependencyInjection\ServicesResetterInterface::class,
    );
}
