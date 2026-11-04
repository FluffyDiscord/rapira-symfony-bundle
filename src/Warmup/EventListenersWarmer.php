<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class EventListenersWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?EventDispatcherInterface $eventDispatcher = null,
    )
    {
    }

    public function warmup(): void
    {
        $this->eventDispatcher?->getListeners();
    }
}
