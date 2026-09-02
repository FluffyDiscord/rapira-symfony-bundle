<?php

namespace FluffyDiscord\RapiraBundle\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

trait RapiraMicroKernelTrait
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if (true === $this->booted) {
            if ($this->debug) {
                $this->startTime = microtime(true);
            }
            return;
        }

        if (null === $this->container) {
            $reflectionClass = new \ReflectionClass($this);
            $preBootMethod = $reflectionClass->getMethod("preBoot");
            $preBootMethod->invoke($this);
        }

        foreach ($this->getBundles() as $bundle) {
            $bundle->setContainer($this->container);
            $bundle->boot();
        }

        $this->booted = true;
    }
}