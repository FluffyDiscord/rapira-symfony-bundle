<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

/**
 * @invariant Wire to router.default, since decorators hide the concrete Router behind the
 *            router alias. Never call match(): custom routers run real lookups there.
 */
readonly class RouterWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private ?RouterInterface $router = null,
    )
    {
    }

    public function warmup(): void
    {
        if (!$this->router instanceof Router) {
            return;
        }

        $this->router->getMatcher();
        $this->router->getGenerator();
    }
}
