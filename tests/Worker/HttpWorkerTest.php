<?php

namespace FluffyDiscord\RapiraBundle\Tests\Worker;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactory;
use FluffyDiscord\RapiraBundle\Tests\Double\RecordingExchange;
use FluffyDiscord\RapiraBundle\Tests\Double\RecordingServicesResetter;
use FluffyDiscord\RapiraBundle\Tests\Double\ScriptedDispatcher;
use FluffyDiscord\RapiraBundle\Tests\Double\WorkerKernelInterface;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Rapira\Http\Exchange;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

class HttpWorkerTest extends RapiraTestCase
{
    /**
     * @param list<Exchange> $exchanges
     */
    private function worker(WorkerKernelInterface $kernel, array $exchanges, ?ServicesResetterInterface $servicesResetter = null): HttpWorker
    {
        return new HttpWorker(
            $kernel,
            new EventDispatcher(),
            false,
            new ScriptedDispatcher($exchanges),
            new SymfonyRequestFactory('/srv/app/public/index.php'),
            $servicesResetter ?? new ServicesResetter(new \ArrayIterator([]), []),
            null,
        );
    }

    public function testHappyPathHandlesEveryExchangeAndTerminates(): void
    {
        $exchanges = [
            new RecordingExchange($this->makeRequest(target: '/a')),
            new RecordingExchange($this->makeRequest(target: '/b')),
            new RecordingExchange($this->makeRequest(target: '/c')),
        ];

        $kernel = $this->createMock(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(fn(Request $request) => new Response('body:' . $request->getPathInfo()));
        $kernel->expects(self::exactly(3))->method('terminate');
        $kernel->expects(self::never())->method('reboot');

        $this->worker($kernel, $exchanges)->start();

        foreach ($exchanges as $exchange) {
            self::assertInstanceOf(RecordingExchange::class, $exchange);
            self::assertSame(200, $exchange->heads[0]['status']);
            self::assertTrue($exchange->isFinalized());
        }
        self::assertSame('body:/a', $exchanges[0]->bodyWrites[0]['content']);
    }

    public function testThrowableWritesFiveHundredAndRebootsKernel(): void
    {
        $exchange = new RecordingExchange($this->makeRequest());

        $kernel = $this->createMock(WorkerKernelInterface::class);
        $kernel->method('handle')->willThrowException(new \RuntimeException('handler blew up'));
        $kernel->expects(self::once())->method('reboot')->with(null);
        $kernel->expects(self::never())->method('terminate');

        $this->worker($kernel, [$exchange])->start();

        self::assertSame(500, $exchange->heads[0]['status']);
    }

    public function testHostClosedExchangeIsSwallowedWithoutRebooting(): void
    {
        $closed = new RecordingExchange($this->makeRequest(target: '/closed'), throwWorkDiscardedOnBodyWrite: 1);
        $healthy = new RecordingExchange($this->makeRequest(target: '/healthy'));

        $kernel = $this->createMock(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturn(new Response('ok'));
        $kernel->expects(self::never())->method('reboot');

        $this->worker($kernel, [$closed, $healthy])->start();

        self::assertCount(1, $closed->heads);
        self::assertFalse($closed->isFinalized());
        self::assertTrue($healthy->isFinalized());
    }

    public function testResetAfterARebootTargetsTheNewContainer(): void
    {
        $bootResetter = new RecordingServicesResetter();
        $rebootedResetter = new RecordingServicesResetter();

        $bootContainer = new Container();
        $bootContainer->set('services_resetter', $bootResetter);

        $rebootedContainer = new Container();
        $rebootedContainer->set('services_resetter', $rebootedResetter);

        $liveContainer = $bootContainer;

        $kernel = $this->createMock(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(static function (Request $request): Response {
            if ($request->getPathInfo() === '/boom') {
                throw new \RuntimeException('handler blew up');
            }

            return new Response('ok');
        });
        $kernel->expects(self::once())->method('reboot')->willReturnCallback(static function () use (&$liveContainer, $rebootedContainer): void {
            $liveContainer = $rebootedContainer;
        });
        $kernel->method('getContainer')->willReturnCallback(static function () use (&$liveContainer): Container {
            return $liveContainer;
        });

        $exchanges = [
            new RecordingExchange($this->makeRequest(target: '/before')),
            new RecordingExchange($this->makeRequest(target: '/boom')),
            new RecordingExchange($this->makeRequest(target: '/after')),
        ];

        $this->worker($kernel, $exchanges, $bootResetter)->start();

        self::assertSame(1, $bootResetter->resetCount, 'the boot container is reset while it is the live one');
        self::assertSame(1, $rebootedResetter->resetCount, 'the rebooted container is reset once the reboot replaced it');
    }

    public function testWorkerEventsAfterARebootReachTheNewContainersDispatcher(): void
    {
        $rebootedDispatcher = new EventDispatcher();
        $responsesSeenAfterReboot = 0;
        $rebootedDispatcher->addListener(WorkerResponseSentEvent::class, static function () use (&$responsesSeenAfterReboot): void {
            $responsesSeenAfterReboot++;
        });

        $rebootedContainer = new Container();
        $rebootedContainer->set('event_dispatcher', $rebootedDispatcher);

        $kernel = $this->createMock(WorkerKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(static function (Request $request): Response {
            if ($request->getPathInfo() === '/boom') {
                throw new \RuntimeException('handler blew up');
            }

            return new Response('ok');
        });
        $kernel->method('getContainer')->willReturn($rebootedContainer);
        $kernel->expects(self::once())->method('reboot')->with(null);

        $exchanges = [
            new RecordingExchange($this->makeRequest(target: '/boom')),
            new RecordingExchange($this->makeRequest(target: '/after')),
        ];

        $this->worker($kernel, $exchanges)->start();

        self::assertSame(1, $responsesSeenAfterReboot);
    }
}
