<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SessionSlowSetController
{
    #[Route('/session/slow-set/{value}', methods: [Request::METHOD_GET])]
    public function __invoke(Request $request, string $value): Response
    {
        $session = $request->getSession();
        $session->start();
        sleep(2);
        $session->set('marker', $value);

        return new Response('set:' . $value);
    }
}
