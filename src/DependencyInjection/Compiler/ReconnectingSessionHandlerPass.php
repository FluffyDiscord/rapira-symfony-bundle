<?php

namespace FluffyDiscord\RapiraBundle\DependencyInjection\Compiler;

use FluffyDiscord\RapiraBundle\Session\ReconnectingSessionHandlerFactory;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

readonly class ReconnectingSessionHandlerPass implements CompilerPassInterface
{
    private const string INNER_HANDLER_ID = '.rapira.session.inner_handler';

    public function process(ContainerBuilder $container): void
    {
        $isEnabled = $container->hasDefinition(ReconnectingSessionHandlerFactory::class);
        $hasSessionHandler = $container->has('session.handler');
        if (!$isEnabled || !$hasSessionHandler) {
            return;
        }

        $handlerId = $this->getDefinitionId($container, 'session.handler');
        $definition = $container->getDefinition($handlerId);

        $canBePdoHandler = $this->canBePdoHandler($handlerId, $definition);
        if (!$canBePdoHandler) {
            return;
        }

        $innerDefinition = clone $definition;
        $innerDefinition
            ->setShared(false)
            ->setPublic(false)
            ->setTags([])
            ->setDecoratedService(null);
        $this->addPersistentConnectionOption($innerDefinition);
        $container->setDefinition(self::INNER_HANDLER_ID, $innerDefinition);

        $handlerDefinition = new Definition($definition->getClass() ?? $handlerId);
        $handlerDefinition
            ->setFactory([new Reference(ReconnectingSessionHandlerFactory::class), 'createHandler'])
            ->setArguments([new ServiceClosureArgument(new Reference(self::INNER_HANDLER_ID))])
            ->setPublic($definition->isPublic())
            ->setShared($definition->isShared())
            ->setTags($definition->getTags());
        $container->setDefinition($handlerId, $handlerDefinition);
    }

    private function getDefinitionId(ContainerBuilder $container, string $id): string
    {
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }

    private function canBePdoHandler(string $handlerId, Definition $definition): bool
    {
        $isUnusable = $definition->isAbstract() || $definition->isSynthetic();
        $isDecorator = $definition->getDecoratedService() !== null;
        if ($isUnusable || $isDecorator) {
            return false;
        }

        $isFrameworkDsnHandler = $handlerId === 'session.abstract_handler';
        if ($isFrameworkDsnHandler) {
            return true;
        }

        $class = $definition->getClass() ?? $handlerId;

        return is_a($class, PdoSessionHandler::class, true);
    }

    private function addPersistentConnectionOption(Definition $definition): void
    {
        $arguments = $definition->getArguments();
        $hasNamedOptions = \array_key_exists('$options', $arguments);
        $optionsKey = $hasNamedOptions ? '$options' : 1;

        $options = $arguments[$optionsKey] ?? [];
        if (!\is_array($options)) {
            return;
        }

        $connectionOptions = $options['db_connection_options'] ?? [];
        if (!\is_array($connectionOptions)) {
            return;
        }

        $connectionOptions += [\PDO::ATTR_PERSISTENT => true];
        $options['db_connection_options'] = $connectionOptions;
        $definition->setArgument($optionsKey, $options);
    }
}
