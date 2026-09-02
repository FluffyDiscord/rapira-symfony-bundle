<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class StreamController
{
    #[Route('/stream', methods: ['GET'])]
    public function __invoke(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            for ($i = 1; $i <= 3; $i++) {
                echo "chunk-{$i}\n";
                flush();
                usleep(300_000);
            }
        });
    }
}
