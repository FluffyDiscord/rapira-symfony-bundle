<?php

namespace FluffyDiscord\RapiraBundle\Event\Worker;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

class WorkerResponseSentEvent extends Event
{
    public function __construct(
        public readonly Request  $request,
        public readonly Response $response,
    )
    {
    }
}
