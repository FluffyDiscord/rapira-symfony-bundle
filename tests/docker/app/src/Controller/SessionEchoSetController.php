<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SessionEchoSetController
{
    #[Route('/session/echo-set/{value}', methods: ['GET'])]
    public function __invoke(Request $request, string $value): Response
    {
        echo 'stray-output';

        $session = $request->getSession();
        $session->set('marker', $value);

        return new Response('set:' . $value);
    }
}
