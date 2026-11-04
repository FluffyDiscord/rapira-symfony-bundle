<?php

namespace FluffyDiscord\RapiraBundle\DependencyInjection\Compiler;

use FluffyDiscord\RapiraBundle\HttpCache\ResettableStore;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\HttpCache\Store;

readonly class ResettableHttpCacheStorePass implements CompilerPassInterface
{
    private const string STORE_ID = 'http_cache.store';

    public function process(ContainerBuilder $container): void
    {
        $hasStore = $container->hasDefinition(self::STORE_ID);
        if (!$hasStore) {
            return;
        }

        $definition = $container->getDefinition(self::STORE_ID);
        $isStockStore = $definition->getClass() === Store::class;
        if (!$isStockStore) {
            return;
        }

        $definition
            ->setClass(ResettableStore::class)
            ->addTag('kernel.reset', ['method' => 'reset', 'on_invalid' => 'ignore']);
    }
}
