<?php

namespace FluffyDiscord\RapiraBundle\Tests\DependencyInjection;

use FluffyDiscord\RapiraBundle\DependencyInjection\Compiler\ReconnectingSessionHandlerPass;
use FluffyDiscord\RapiraBundle\Session\ReconnectingPdoSessionHandler;
use FluffyDiscord\RapiraBundle\Session\ReconnectingSessionHandlerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\SessionHandlerFactory;

class ReconnectingSessionHandlerPassTest extends TestCase
{
    private const string INNER_HANDLER_ID = '.rapira.session.inner_handler';

    public function testFrameworkDsnHandlerGetsPersistentConnectionAndFactory(): void
    {
        $container = $this->createContainer();
        $definition = new Definition(AbstractSessionHandler::class, ['%env(DATABASE_URL)%', []]);
        $definition->setFactory([SessionHandlerFactory::class, 'createHandler']);
        $container->setDefinition('session.abstract_handler', $definition);
        $container->setAlias('session.handler', 'session.abstract_handler');

        new ReconnectingSessionHandlerPass()->process($container);

        $this->assertWrapped($container, 'session.abstract_handler');
        $inner = $container->getDefinition(self::INNER_HANDLER_ID);
        self::assertSame([SessionHandlerFactory::class, 'createHandler'], $inner->getFactory());
        self::assertSame(['%env(DATABASE_URL)%', ['db_connection_options' => [\PDO::ATTR_PERSISTENT => true]]], $inner->getArguments());
    }

    public function testCompiledContainerReturnsReconnectingHandler(): void
    {
        $container = $this->createContainer();
        $definition = new Definition(AbstractSessionHandler::class, ['sqlite:///:memory:', []]);
        $definition->setFactory([SessionHandlerFactory::class, 'createHandler']);
        $container->setDefinition('session.abstract_handler', $definition);
        $container->setAlias('session.handler', 'session.abstract_handler')->setPublic(true);
        $container->addCompilerPass(new ReconnectingSessionHandlerPass());

        $container->compile();
        $handler = $container->get('session.handler');

        self::assertInstanceOf(ReconnectingPdoSessionHandler::class, $handler);
        self::assertSame($handler, $container->get('session.handler'));
    }

    public function testUserPdoHandlerKeepsItsOptions(): void
    {
        $container = $this->createContainer();
        $options = ['db_table' => 'app_sessions', 'db_connection_options' => [\PDO::ATTR_TIMEOUT => 5]];
        $container->setDefinition('app.session_handler', new Definition(PdoSessionHandler::class, ['%env(DATABASE_URL)%', $options]));
        $container->setAlias('app.session_handler_alias', 'app.session_handler');
        $container->setAlias('session.handler', 'app.session_handler_alias');

        new ReconnectingSessionHandlerPass()->process($container);

        $this->assertWrapped($container, 'app.session_handler');
        $innerOptions = $container->getDefinition(self::INNER_HANDLER_ID)->getArgument(1);
        self::assertSame(
            ['db_table' => 'app_sessions', 'db_connection_options' => [\PDO::ATTR_TIMEOUT => 5, \PDO::ATTR_PERSISTENT => true]],
            $innerOptions,
        );
    }

    public function testNamedOptionsArgumentIsUsed(): void
    {
        $container = $this->createContainer();
        $container->setDefinition(PdoSessionHandler::class, new Definition(null, ['$pdoOrDsn' => '%env(DATABASE_URL)%', '$options' => ['db_table' => 's']]));
        $container->setAlias('session.handler', PdoSessionHandler::class);

        new ReconnectingSessionHandlerPass()->process($container);

        $innerArguments = $container->getDefinition(self::INNER_HANDLER_ID)->getArguments();
        self::assertSame(['db_table' => 's', 'db_connection_options' => [\PDO::ATTR_PERSISTENT => true]], $innerArguments['$options']);
        self::assertArrayNotHasKey(1, $innerArguments);
    }

    public function testExplicitPersistentFalseIsRespected(): void
    {
        $container = $this->createContainer();
        $options = ['db_connection_options' => [\PDO::ATTR_PERSISTENT => false]];
        $container->setDefinition('app.session_handler', new Definition(PdoSessionHandler::class, ['%env(DATABASE_URL)%', $options]));
        $container->setAlias('session.handler', 'app.session_handler');

        new ReconnectingSessionHandlerPass()->process($container);

        self::assertSame($options, $container->getDefinition(self::INNER_HANDLER_ID)->getArgument(1));
    }

    public function testFileHandlerIsUntouched(): void
    {
        $container = $this->createContainer();
        $definition = new Definition(NativeFileSessionHandler::class);
        $container->setDefinition('session.handler.native_file', $definition);
        $container->setAlias('session.handler', 'session.handler.native_file');

        new ReconnectingSessionHandlerPass()->process($container);

        self::assertSame($definition, $container->getDefinition('session.handler.native_file'));
        self::assertFalse($container->hasDefinition(self::INNER_HANDLER_ID));
    }

    public function testDisabledKeepsHandlerUntouched(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(PdoSessionHandler::class, ['%env(DATABASE_URL)%']);
        $container->setDefinition('app.session_handler', $definition);
        $container->setAlias('session.handler', 'app.session_handler');

        new ReconnectingSessionHandlerPass()->process($container);

        self::assertSame($definition, $container->getDefinition('app.session_handler'));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ReconnectingSessionHandlerFactory::class, new Definition(ReconnectingSessionHandlerFactory::class));

        return $container;
    }

    private function assertWrapped(ContainerBuilder $container, string $handlerId): void
    {
        $handler = $container->getDefinition($handlerId);
        $inner = $container->getDefinition(self::INNER_HANDLER_ID);

        self::assertEquals([new Reference(ReconnectingSessionHandlerFactory::class), 'createHandler'], $handler->getFactory());
        self::assertEquals([new ServiceClosureArgument(new Reference(self::INNER_HANDLER_ID))], $handler->getArguments());
        self::assertFalse($inner->isShared());
    }
}
