<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;

readonly class SingleConnectionRegistry implements ConnectionRegistry
{
    public function __construct(
        private Connection $connection,
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
}
