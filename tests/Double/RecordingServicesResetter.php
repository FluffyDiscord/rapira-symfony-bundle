<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * Counts reset() calls so a test can tell which container's resetter the worker used.
 */
class RecordingServicesResetter implements ServicesResetterInterface
{
    public int $resetCount = 0;

    public function reset(): void
    {
        $this->resetCount++;
    }
}
