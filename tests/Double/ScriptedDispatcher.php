<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use FluffyDiscord\RapiraBundle\Worker\DispatcherInterface;
use Rapira\Http\Exchange;

class ScriptedDispatcher implements DispatcherInterface
{
    /**
     * @param list<Exchange> $exchanges served in order; the loop ends when they run out
     */
    public function __construct(
        private array $exchanges,
    )
    {
    }

    public function receive(): ?Exchange
    {
        return array_shift($this->exchanges);
    }
}
