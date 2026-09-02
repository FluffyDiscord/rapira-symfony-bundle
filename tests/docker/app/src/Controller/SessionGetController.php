<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SessionGetController
{
    #[Route('/session/get', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $marker = $session->get('marker', 'anonymous');
        assert(is_string($marker));

        return new Response('marker:' . $marker);
    }
}
