<?php

use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Symfony\Component\HttpFoundation\Response;

function headers_send(int $statusCode = 200): int
{
    $exchange = HttpWorker::$currentExchange;
    if ($exchange === null) {
        return $statusCode;
    }

    $isInformational = $statusCode >= 100 && $statusCode < 200;
    if (!$isInformational) {
        return $statusCode;
    }

    $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
    $response = $backtrace[1]['object'] ?? null;
    if (!$response instanceof Response) {
        return $statusCode;
    }

    /** @var array<non-empty-string, list<string>> $headers */
    $headers = $response->headers->allPreserveCaseWithoutCookies();
    $exchange->writeHead($statusCode, $headers);

    return $statusCode;
}
