<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\Result;

class KillableDriver extends AbstractDriverMiddleware
{
    public int $connectCount = 0;

    private bool $killed = false;

    public function __construct()
    {
        parent::__construct(new SQLiteDriver());
    }

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        $this->connectCount++;
        $this->killed = false;

        $driver = $this;

        return new class (parent::connect(['memory' => true]), $driver) extends AbstractConnectionMiddleware {
            public function __construct(
                DriverConnection                $wrappedConnection,
                private readonly KillableDriver $driver,
            )
            {
                parent::__construct($wrappedConnection);
            }

            public function query(string $sql): Result
            {
                $isKilled = $this->driver->isKilled();
                if ($isKilled) {
                    throw new class ('server closed the connection unexpectedly') extends AbstractException {
                    };
                }

                return parent::query($sql);
            }
        };
    }

    public function kill(): void
    {
        $this->killed = true;
    }

    public function isKilled(): bool
    {
        return $this->killed;
    }
}
