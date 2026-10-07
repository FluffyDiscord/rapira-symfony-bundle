<?php

namespace FluffyDiscord\RapiraBundle\Tests\Runtime;

use FluffyDiscord\RapiraBundle\Runtime\Runner;
use FluffyDiscord\RapiraBundle\Runtime\Runtime;
use PHPUnit\Framework\TestCase;
use Rapira\Internal\Double;
use Rapira\Internal\Runtime as RapiraRuntime;
use Rapira\Mode;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\Runner\Symfony\HttpKernelRunner;

class RuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Double::setRuntime(null);
    }

    public function testOutsideRapiraTheKernelGetsTheStockSymfonyRunner(): void
    {
        $runtime = new Runtime(['disable_dotenv' => true, 'error_handler' => false]);
        $kernel = $this->createStub(KernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(HttpKernelRunner::class, $runner);
    }

    public function testDispatcherModeGetsTheRapiraRunnerWhateverTheSapiName(): void
    {
        Double::setRuntime(new class extends RapiraRuntime {
            public function mode(): Mode
            {
                return Mode::Dispatcher;
            }
        });

        $runtime = new Runtime(['disable_dotenv' => true, 'error_handler' => false]);
        $kernel = $this->createStub(KernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(Runner::class, $runner);
    }
}
