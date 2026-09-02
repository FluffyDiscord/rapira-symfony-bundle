<?php

namespace FluffyDiscord\RapiraBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('rapira');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('http')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('lazy_boot')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('warmup')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('learn')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('learn_requests')
                            ->min(1)
                            ->defaultValue(30)
                        ->end()
                        ->scalarNode('manifest_path')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn($value) => $value !== null && !is_string($value))
                                ->thenInvalid('warmup.manifest_path must be a string or null.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('preconnect')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('profiling')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('xhprof')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('enabled')
                                    ->defaultValue(false)
                                    ->validate()
                                        ->ifTrue(static fn($value) => !in_array($value, ['auto', true, false], true))
                                        ->thenInvalid('profiling.xhprof.enabled must be "auto", true or false.')
                                    ->end()
                                ->end()
                                ->scalarNode('output_dir')
                                    ->defaultNull()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('vips')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('enabled')
                            ->defaultValue('auto')
                            ->validate()
                                ->ifTrue(static fn($value) => !in_array($value, ['auto', true, false], true))
                                ->thenInvalid('vips.enabled must be "auto", true or false.')
                            ->end()
                        ->end()
                        ->integerNode('max_operations')
                            ->min(0)
                            ->defaultValue(50)
                        ->end()
                        ->integerNode('max_memory_mb')
                            ->min(0)
                            ->defaultValue(50)
                        ->end()
                        ->integerNode('max_files')
                            ->min(0)
                            ->defaultValue(20)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
