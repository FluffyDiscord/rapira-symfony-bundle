<?php

namespace FluffyDiscord\RapiraBundle\Tests\Runtime;

use FluffyDiscord\RapiraBundle\Runtime\Runtime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\Runner\Symfony\HttpKernelRunner;

class RuntimeTest extends TestCase
{
    public function testOutsideRapiraTheKernelGetsTheStockSymfonyRunner(): void
    {
        $runtime = new Runtime(['disable_dotenv' => true, 'error_handler' => false]);
        $kernel = $this->createStub(KernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(HttpKernelRunner::class, $runner);
    }
}
