<?php

namespace FluffyDiscord\RapiraBundle\Factory;

use Rapira\Http\Request as RapiraRequest;
use Symfony\Component\HttpFoundation\Request;

interface SymfonyRequestFactoryInterface
{
    public function createRequest(RapiraRequest $rapiraRequest): Request;
}
