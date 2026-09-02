<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

/**
 * Implementations are collected via the "fluffy_discord.rapira.worker_warmer" tag
 * (autoconfigured) and executed by WorkerWarmupRunner while the worker boots, before it
 * enters the request loop.
 */
interface WorkerWarmerInterface
{
    public function warmup(): void;
}
