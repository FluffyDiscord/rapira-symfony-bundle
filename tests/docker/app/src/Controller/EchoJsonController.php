<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EchoJsonController
{
    #[Route('/echo-json', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return new Response($request->getContent(), Response::HTTP_OK, ['content-type' => 'application/json']);
    }
}
