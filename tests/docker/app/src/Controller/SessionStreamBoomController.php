<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class SessionStreamBoomController
{
    #[Route('/session/stream-boom', methods: ['GET'])]
    public function __invoke(Request $request): StreamedResponse
    {
        $session = $request->getSession();

        return new StreamedResponse(function () use ($session): void {
            $marker = $session->get('marker', 'anonymous');
            assert(is_string($marker));

            echo 'marker:' . $marker;
            throw new \RuntimeException('session stream blew up after the head was written');
        });
    }
}
