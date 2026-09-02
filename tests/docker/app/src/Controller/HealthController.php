<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    #[Route('/health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('ready', Response::HTTP_CREATED);
    }
}
