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
    )
    {
    }

    #[Route('/', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('OK marker=' . $this->marker . ' pid=' . getmypid());
    }
}
