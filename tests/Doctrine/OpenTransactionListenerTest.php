<?php

namespace FluffyDiscord\RapiraBundle\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use FluffyDiscord\RapiraBundle\Doctrine\OpenTransactionListener;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFinishedEvent;
use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactory;
use FluffyDiscord\RapiraBundle\Tests\Double\RecordingExchange;
use FluffyDiscord\RapiraBundle\Tests\Double\RecordingServicesResetter;
use FluffyDiscord\RapiraBundle\Tests\Double\ScriptedDispatcher;
use FluffyDiscord\RapiraBundle\Tests\Double\SingleConnectionRegistry;
use FluffyDiscord\RapiraBundle\Tests\Double\WorkerKernelInterface;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OpenTransactionListenerTest extends RapiraTestCase
{
    public function testTransactionLeftOpenByAFailedRequestIsRolledBackBeforeTheNextRequest(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

        $leaking = new RecordingExchange($this->makeRequest(method: 'POST', target: '/checkout/address'));
        $next = new RecordingExchange($this->makeRequest(target: '/next'));
        $transactionSeenByNextRequest = null;

        $kernel = $this->createStub(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(function (Request $request) use ($connection, &$transactionSeenByNextRequest): Response {
            if ($request->getPathInfo() === '/next') {
                $transactionSeenByNextRequest = $connection->isTransactionActive();

                return new Response('ok');
            }

            $connection->beginTransaction();
            $connection->beginTransaction();
            $connection->executeStatement('INSERT INTO orders (id) VALUES (1)');
            $connection->rollBack();

            return new Response('error page', 500);
        });

        $logger = $this->createLogger();
        $this->createWorker($kernel, [$leaking, $next], $connection, $logger)->start();

        self::assertFalse($transactionSeenByNextRequest);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM orders'));
        self::assertSame(['Rapira: POST http://shop.example/checkout/address left an open transaction on Doctrine connection "default"; rolled it back.'], $logger->errors);
    }

    public function testTransactionLeftOpenWhenTheClientDisconnectedIsRolledBack(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $closed = new RecordingExchange($this->makeRequest(target: '/closed'), throwWorkDiscardedOnBodyWrite: 1);

        $kernel = $this->createStub(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(function () use ($connection): Response {
            $connection->beginTransaction();

            return new Response('ok');
        });

        $this->createWorker($kernel, [$closed], $connection, $this->createLogger())->start();

        self::assertFalse($connection->isTransactionActive());
    }

    public function testCommittedWorkIsLeftAlone(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

        $kernel = $this->createStub(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(function () use ($connection): Response {
            $connection->transactional(fn(Connection $connection): int => $connection->executeStatement('INSERT INTO orders (id) VALUES (1)'));

            return new Response('ok');
        });

        $logger = $this->createLogger();
        $this->createWorker($kernel, [new RecordingExchange($this->makeRequest())], $connection, $logger)->start();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM orders'));
        self::assertSame([], $logger->errors);
    }

    /**
     * @param list<RecordingExchange> $exchanges
     */
    private function createWorker(WorkerKernelInterface $kernel, array $exchanges, Connection $connection, AbstractLogger $logger): HttpWorker
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(WorkerRequestFinishedEvent::class, new OpenTransactionListener(new SingleConnectionRegistry($connection), $logger));

        return new HttpWorker(
            $kernel,
            $eventDispatcher,
            false,
            new ScriptedDispatcher($exchanges),
            new SymfonyRequestFactory('/srv/app/public/index.php', new ScriptedDispatcher([])),
            new RecordingServicesResetter(),
            null,
        );
    }

    private function createLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level !== 'error') {
                    return;
                }

                $replacements = [];
                foreach ($context as $key => $value) {
                    $replacements['{' . $key . '}'] = (string) $value;
                }

                $this->errors[] = strtr((string) $message, $replacements);
            }
        };
    }
}
