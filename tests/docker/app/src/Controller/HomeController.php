<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController
{
    public function __construct(
        #[Autowire('%env(RAPIRA_TEST_MARKER)%')]
        private readonly string $marker,

        #[Autowire('%kernel.runtime_mode.worker%')]
        private readonly bool   $isWorker,
    )
    {
    }

    #[Route('/', methods: ['GET'])]
    public function __invoke(): Response
    {
        $workerFlag = $this->isWorker ? '1' : '0';

        return new Response('OK marker=' . $this->marker . ' worker=' . $workerFlag . ' pid=' . getmypid());
    }
}
