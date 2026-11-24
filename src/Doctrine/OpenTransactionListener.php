<?php

namespace FluffyDiscord\RapiraBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFinishedEvent;
use Psr\Log\LoggerInterface;

readonly class OpenTransactionListener
{
    public function __construct(
        private ?ConnectionRegistry $registry,
        private ?LoggerInterface    $logger = null,
    )
    {
    }

    public function __invoke(WorkerRequestFinishedEvent $event): void
    {
        foreach ($this->getConnections() as $name => $connection) {
            $isTransactionActive = $connection->isTransactionActive();
            if (!$isTransactionActive) {
                continue;
            }

            $this->rollBack($connection);

            $this->logger?->error(
                'Rapira: {method} {uri} left an open transaction on Doctrine connection "{connection}"; rolled it back.',
                [
                    'connection' => (string) $name,
                    'method' => $event->request->method ?? '-',
                    'uri' => $event->request->uri ?? '-',
                ],
            );
        }
    }

    /**
     * @return array<array-key, Connection>
     */
    private function getConnections(): array
    {
        if ($this->registry === null) {
            return [];
        }

        try {
            $connections = $this->registry->getConnections();
        } catch (\Throwable $throwable) {
            $this->logger?->warning(
                'Rapira: unable to enumerate Doctrine connections; skipping the open transaction check.',
                ['exception' => $throwable],
            );

            return [];
        }

        return array_filter($connections, static fn(object $connection): bool => $connection instanceof Connection);
    }

    private function rollBack(Connection $connection): void
    {
        try {
            $isTransactionActive = $connection->isTransactionActive();
            while ($isTransactionActive) {
                $connection->rollBack();
                $isTransactionActive = $connection->isTransactionActive();
            }
        } catch (\Throwable) {
            $connection->close();
        }
    }
}
