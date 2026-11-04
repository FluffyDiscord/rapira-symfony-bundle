<?php

namespace FluffyDiscord\RapiraBundle\Worker;

use Rapira\Http\Exchange;

interface DispatcherInterface
{
    /**
     * @return Exchange|null Null when no more work will come for this app instance.
     */
    public function receive(): ?Exchange;

    public function getServerName(): string;

    public function getServerPort(): int;
}
