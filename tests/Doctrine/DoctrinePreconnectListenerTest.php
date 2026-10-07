<?php

namespace FluffyDiscord\RapiraBundle\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RapiraBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RapiraBundle\Tests\Double\KillableDriver;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DoctrinePreconnectListenerTest extends RapiraTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideServerDatabaseDrivers(): iterable
    {
        yield 'pdo_pgsql' => ['pdo_pgsql'];
        yield 'pgsql' => ['pgsql'];
        yield 'pdo_mysql' => ['pdo_mysql'];
        yield 'mysqli' => ['mysqli'];
    }

    #[DataProvider('provideServerDatabaseDrivers')]
    public function testBootOpensServerDatabaseConnection(string $driverName): void
    {
        $driver = new KillableDriver();
        $connection = new Connection(['driver' => $driverName], $driver);

        $this->createListener($connection)->__invoke(new WorkerBootingEvent());

        self::assertTrue($connection->isConnected());
        self::assertSame(1, $driver->connectCount);
    }

    public function testBootSkipsSqlite(): void
    {
        $driver = new KillableDriver();
        $connection = new Connection(['driver' => 'pdo_sqlite'], $driver);

        $this->createListener($connection)->__invoke(new WorkerBootingEvent());

        self::assertFalse($connection->isConnected());
    }

    #[DataProvider('provideServerDatabaseDrivers')]
    public function testRequestKeepsLiveConnection(string $driverName): void
    {
        $driver = new KillableDriver();
        $connection = new Connection(['driver' => $driverName], $driver);
        $listener = $this->createListener($connection);
        $listener->__invoke(new WorkerBootingEvent());

        $listener->onRequestReceived(new WorkerRequestReceivedEvent($this->makeRequest()));
        $listener->onRequestReceived(new WorkerRequestReceivedEvent($this->makeRequest()));

        self::assertTrue($connection->isConnected());
        self::assertSame(1, $driver->connectCount);
    }

    #[DataProvider('provideServerDatabaseDrivers')]
    public function testRequestReconnectsDeadConnection(string $driverName): void
    {
        $driver = new KillableDriver();
        $connection = new Connection(['driver' => $driverName], $driver);
        $listener = $this->createListener($connection);
        $listener->__invoke(new WorkerBootingEvent());

        $driver->kill();
        $listener->onRequestReceived(new WorkerRequestReceivedEvent($this->makeRequest()));

        self::assertTrue($connection->isConnected());
        self::assertSame(2, $driver->connectCount);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testRequestLeavesUnopenedConnectionLazy(): void
    {
        $driver = new KillableDriver();
        $connection = new Connection(['driver' => 'pdo_pgsql'], $driver);

        $this->createListener($connection)->onRequestReceived(new WorkerRequestReceivedEvent($this->makeRequest()));

        self::assertFalse($connection->isConnected());
        self::assertSame(0, $driver->connectCount);
    }

    private function createListener(Connection $connection): DoctrinePreconnectListener
    {
        $registry = new class ($connection) implements ConnectionRegistry {
            public function __construct(
                private readonly Connection $connection,
            )
            {
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function getConnection(?string $name = null): object
            {
                return $this->connection;
            }

            public function getConnections(): array
            {
                return ['default' => $this->connection];
            }

            public function getConnectionNames(): array
            {
                return ['default' => 'doctrine.dbal.default_connection'];
            }
        };

        return new DoctrinePreconnectListener($registry);
    }
}
