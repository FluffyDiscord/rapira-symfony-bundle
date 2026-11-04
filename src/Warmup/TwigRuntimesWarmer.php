<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

readonly class TwigRuntimesWarmer implements WorkerWarmerInterface
{
    /**
     * @param iterable<object> $twigRuntimes services tagged twig.runtime
     */
    public function __construct(
        private iterable $twigRuntimes = [],
    )
    {
    }

    public function warmup(): void
    {
        foreach ($this->twigRuntimes as $twigRuntime) {
            // @invariant Advancing the lazy tagged iterator instantiates the runtime; this loop is the warm-up.
        }
    }
}
