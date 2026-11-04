<?php

namespace FluffyDiscord\RapiraBundle\Kernel;

use Rapira\Mode;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @require-extends Kernel
 */
trait RapiraMicroKernelTrait
{
    use MicroKernelTrait;

    public function boot(): void
    {
        $isBootedInRapiraDispatcher = $this->booted && \Rapira\get_mode() === Mode::Dispatcher;
        if ($isBootedInRapiraDispatcher) {
            if ($this->debug) {
                $this->startTime = microtime(true);
            }

            return;
        }

        parent::boot();
    }
}
