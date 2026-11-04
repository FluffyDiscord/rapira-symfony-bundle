<?php

namespace FluffyDiscord\RapiraBundle\Worker;

use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;

readonly class RapiraDispatcher implements DispatcherInterface
{
    private HttpDispatcher $dispatcher;

    public function __construct()
    {
        $dispatcher = \Rapira\get_dispatcher();
        assert($dispatcher instanceof HttpDispatcher);
        $this->dispatcher = $dispatcher;
    }

    public function receive(): ?Exchange
    {
        try {
            return $this->dispatcher->receive();
        } catch (ClosedException) {
            return null;
        }
    }

    public function getServerName(): string
    {
        return $this->dispatcher->getInfo()->serverName();
    }

    public function getServerPort(): int
    {
        return $this->dispatcher->getInfo()->serverPort();
    }
}
