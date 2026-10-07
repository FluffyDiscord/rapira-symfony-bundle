<?php

namespace FluffyDiscord\RapiraBundle\Session;

use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class ReconnectingPdoSessionHandler extends PdoSessionHandler
{
    private PdoSessionHandler $handler;

    private string $savePath = '';

    private string $sessionName = '';

    /**
     * @param \Closure(): PdoSessionHandler $createHandler
     */
    public function __construct(
        private readonly \Closure $createHandler,
        PdoSessionHandler         $handler,
    )
    {
        parent::__construct();

        $this->handler = $handler;
    }

    public function open(string $savePath, string $sessionName): bool
    {
        $this->savePath = $savePath;
        $this->sessionName = $sessionName;

        try {
            return $this->handler->open($savePath, $sessionName);
        } catch (\PDOException) {
            return $this->reconnect();
        }
    }

    public function validateId(#[\SensitiveParameter] string $sessionId): bool
    {
        return $this->callWithReconnect(fn(PdoSessionHandler $handler): bool => $handler->validateId($sessionId));
    }

    public function read(#[\SensitiveParameter] string $sessionId): string
    {
        return $this->callWithReconnect(fn(PdoSessionHandler $handler): string => $handler->read($sessionId));
    }

    public function write(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        return $this->callWithReconnect(fn(PdoSessionHandler $handler): bool => $handler->write($sessionId, $data));
    }

    public function destroy(#[\SensitiveParameter] string $sessionId): bool
    {
        return $this->callWithReconnect(fn(PdoSessionHandler $handler): bool => $handler->destroy($sessionId));
    }

    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        return $this->callWithReconnect(fn(PdoSessionHandler $handler): bool => $handler->updateTimestamp($sessionId, $data));
    }

    public function gc(int $maxlifetime): int|false
    {
        return $this->handler->gc($maxlifetime);
    }

    public function close(): bool
    {
        try {
            return $this->handler->close();
        } catch (\PDOException $exception) {
            $this->discardFailedHandler();

            throw $exception;
        }
    }

    public function isSessionExpired(): bool
    {
        return $this->handler->isSessionExpired();
    }

    public function configureSchema(Schema $schema, ?\Closure $isSameDatabase = null): Schema
    {
        return $this->handler->configureSchema($schema, $isSameDatabase);
    }

    public function createTable(): void
    {
        $this->handler->createTable();
    }

    /**
     * @template TResult
     *
     * @param \Closure(PdoSessionHandler): TResult $call
     *
     * @return TResult
     */
    private function callWithReconnect(\Closure $call): mixed
    {
        try {
            return $call($this->handler);
        } catch (\PDOException) {
            $this->reconnect();
        }

        try {
            return $call($this->handler);
        } catch (\PDOException $exception) {
            $this->discardFailedHandler();

            throw $exception;
        }
    }

    private function reconnect(): bool
    {
        $this->discardFailedHandler();

        return $this->handler->open($this->savePath, $this->sessionName);
    }

    private function discardFailedHandler(): void
    {
        try {
            $this->handler->close();
        } catch (\PDOException) {
        }

        $this->handler = ($this->createHandler)();
    }
}
