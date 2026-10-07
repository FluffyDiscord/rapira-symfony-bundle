<?php

namespace FluffyDiscord\RapiraBundle\Runtime;

use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

readonly class Runner implements RunnerInterface
{
    public function __construct(
        private KernelInterface $kernel,
    )
    {
    }

    public function run(): int
    {
        $this->setWorkerRuntimeMode();

        $this->kernel->boot();

        $worker = $this->kernel->getContainer()->get(HttpWorker::class);
        assert($worker instanceof HttpWorker);
        $worker->start();

        return 0;
    }

    private function setWorkerRuntimeMode(): void
    {
        $runtimeMode = 'web=1&worker=1';

        $_SERVER['APP_RUNTIME_MODE'] = $runtimeMode;
        $_ENV['APP_RUNTIME_MODE'] = $runtimeMode;
    }
}
