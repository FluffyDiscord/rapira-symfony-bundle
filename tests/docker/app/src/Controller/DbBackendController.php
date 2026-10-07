<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DbBackendController
{
    #[Route('/db/backend', methods: [Request::METHOD_GET])]
    public function __invoke(Connection $connection): Response
    {
        $isPostgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $backendIdQuery = $isPostgres ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()';
        $backendId = $connection->fetchOne($backendIdQuery);

        return new Response('backend:' . $backendId);
    }
}
