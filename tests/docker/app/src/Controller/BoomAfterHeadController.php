<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class BoomAfterHeadController
{
    #[Route('/boom-after-head', methods: ['GET'])]
    public function __invoke(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            echo 'partial-body-before-the-blowup';
            throw new \RuntimeException('handler blew up after the head was written');
        });
    }
}
