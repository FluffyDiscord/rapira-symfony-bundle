<?php

namespace FluffyDiscord\RapiraBundle\Worker;

use Rapira\Http\Exchange;

interface DispatcherInterface
{
    /**
     * Blocks until the next exchange arrives. Null means the dispatcher has drained and
     * no more work will ever come (Rapira's ClosedException), so the worker loop returns.
     */
    public function receive(): ?Exchange;
}
