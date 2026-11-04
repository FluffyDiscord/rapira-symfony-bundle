<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

interface WorkerWarmerInterface
{
    public function warmup(): void;
}
