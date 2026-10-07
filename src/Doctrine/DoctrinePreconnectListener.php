<?php

namespace FluffyDiscord\RapiraBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestReceivedEvent;
use Psr\Log\LoggerInterface;

readonly class DoctrinePreconnectListener
{
    public function __construct(
        private ?ConnectionRegistry $registry,
        private ?LoggerInterface    $logger = null,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        foreach ($this->getServerDatabaseConnections() as $name => $connection) {
            $this->connect($name, $connection);
        }
    }

    public function onRequestReceived(WorkerRequestReceivedEvent $event): void
    {
        foreach ($this->getServerDatabaseConnections() as $name => $connection) {
            $isConnected = $connection->isConnected();
            if (!$isConnected) {
                continue;
            }

            $isAlive = $this->ping($connection);
            if ($isAlive) {
                continue;
            }

            $connection->close();
            $this->connect($name, $connection);
        }
    }

    /**
     * @return array<array-key, Connection>
     */
    private function getServerDatabaseConnections(): array
    {
        if ($this->registry === null) {
            return [];
        }

        try {
            $connections = $this->registry->getConnections();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'Rapira: unable to enumerate Doctrine connections; skipping preconnect.',
                ['exception' => $throwable],
            );

            return [];
        }

        $serverDatabaseConnections = [];
        foreach ($connections as $name => $connection) {
            if (!$connection instanceof Connection) {
                continue;
            }

            $isServerDatabase = $this->isServerDatabase($connection);
            if ($isServerDatabase) {
                $serverDatabaseConnections[$name] = $connection;
            }
        }

        return $serverDatabaseConnections;
    }

    private function isServerDatabase(Connection $connection): bool
    {
        // Driver NAME, not getDriver(): doctrine-bridge/doctrine-bundle wrap the driver in
        // middleware, so getDriver() is never AbstractPostgreSQLDriver. The name is socket-free.
        $driver = $connection->getParams()['driver'] ?? null;

        return \in_array($driver, ['pdo_pgsql', 'pgsql', 'pdo_mysql', 'mysqli'], true);
    }

    private function ping(Connection $connection): bool
    {
        try {
            $dummySelect = $connection->getDatabasePlatform()->getDummySelectSQL();
            $connection->executeQuery($dummySelect);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function connect(int|string $name, Connection $connection): void
    {
        try {
            // Forces the lazy connection open; the returned handle is intentionally unused.
            $connection->getNativeConnection();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'Rapira: unable to connect Doctrine connection "{connection}"; it will be retried lazily on first use.',
                ['connection' => (string) $name, 'exception' => $throwable],
            );
        }
    }
}
