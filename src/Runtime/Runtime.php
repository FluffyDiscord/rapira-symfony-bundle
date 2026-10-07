<?php

namespace FluffyDiscord\RapiraBundle\Runtime;

use Rapira\Mode;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

class Runtime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        $isKernel = $application instanceof KernelInterface;
        $isRapiraDispatcher = $this->isRapiraDispatcher();
        if ($isKernel && $isRapiraDispatcher) {
            return new Runner($application);
        }

        return parent::getRunner($application);
    }

    private function isRapiraDispatcher(): bool
    {
        $mode = \Rapira\get_mode();

        return $mode === Mode::Dispatcher;
    }
}
