<?php

namespace FluffyDiscord\RapiraBundle\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

readonly class ReconnectingSessionHandlerFactory
{
    /**
     * @param \Closure(): \SessionHandlerInterface $createHandler
     */
    public function createHandler(\Closure $createHandler): \SessionHandlerInterface
    {
        $handler = $createHandler();
        if (!$handler instanceof PdoSessionHandler) {
            return $handler;
        }

        $createPdoHandler = fn(): PdoSessionHandler => $this->createPdoHandler($createHandler);

        return new ReconnectingPdoSessionHandler($createPdoHandler, $handler);
    }

    /**
     * @param \Closure(): \SessionHandlerInterface $createHandler
     */
    private function createPdoHandler(\Closure $createHandler): PdoSessionHandler
    {
        $handler = $createHandler();
        if (!$handler instanceof PdoSessionHandler) {
            throw new \LogicException(\sprintf('Expected a fresh %s, got %s.', PdoSessionHandler::class, get_debug_type($handler)));
        }

        return $handler;
    }
}
