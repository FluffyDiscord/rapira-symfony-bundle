<?php

namespace FluffyDiscord\RapiraBundle\Event\Worker;

use Rapira\Http\Request as RapiraRequest;
use Symfony\Contracts\EventDispatcher\Event;

class WorkerRequestReceivedEvent extends Event
{
    public function __construct(
        public readonly RapiraRequest $request,
    )
    {
    }
}
