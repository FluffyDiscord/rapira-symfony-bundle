<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SlowController
{
    #[Route('/slow', methods: ['GET'])]
    public function __invoke(): Response
    {
        sleep(4);

        return new Response('slow-done');
    }
}
