<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\WebLink\Link;

class EarlyHintsController extends AbstractController
{
    #[Route('/early-hints', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = $this->sendEarlyHints([
            (new Link(rel: 'preload', href: '/style.css'))->withAttribute('as', 'style'),
        ]);

        $sapiHeaderCount = \count(headers_list());

        return $response->setContent('hinted sapi_headers=' . $sapiHeaderCount);
    }
}
