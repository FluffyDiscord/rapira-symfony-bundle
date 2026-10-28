<?php

namespace FluffyDiscord\RapiraBundle\Vips;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use Jcupitt\Vips\Config;

/**
 * Bounds libvips's process-global operation cache at worker boot. libvips keeps a cache
 * (1000 operations / 100 MB / 100 files by default) plus static FFI handles on the C heap;
 * under php-fpm that arena died with the request, but a resident worker accumulates it on top
 * of PHP's own memory_limit, invisible to PHP's memory accounting — it surfaces only as an
 * unexplained RSS climb. The cache is bounded rather than disabled so a single request can
 * still reuse variants of one source image.
 */
readonly class VipsCacheLimiter
{
    public function __construct(
        private int  $maxOperations,
        private int  $maxMemoryBytes,
        private int  $maxFiles,
        private bool $isForced = false,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        $ffiRestriction = $this->getFfiRestriction();
        if ($ffiRestriction !== null) {
            if ($this->isForced) {
                $this->log('libvips cache not bounded: ' . $ffiRestriction);
            }

            return;
        }

        try {
            Config::cacheSetMax($this->maxOperations);
            Config::cacheSetMaxMem($this->maxMemoryBytes);
            Config::cacheSetMaxFiles($this->maxFiles);
        } catch (\Throwable $throwable) {
            $this->log('libvips cache not bounded (libvips not loadable): ' . $throwable->getMessage());
        }
    }

    /**
     * Without FFI in this SAPI no request in the worker can load libvips either, so there is no
     * cache to bound.
     */
    private function getFfiRestriction(): ?string
    {
        $isFfiLoaded = \extension_loaded('ffi');
        if (!$isFfiLoaded) {
            return 'the ffi extension is not loaded.';
        }

        $ffiEnable = strtolower((string) \ini_get('ffi.enable'));
        $isFfiEnabled = \in_array($ffiEnable, ['1', 'true', 'on'], true);
        if ($isFfiEnabled) {
            return null;
        }

        $isCli = \PHP_SAPI === 'cli';
        $isPreloadMode = $ffiEnable === 'preload';
        if ($isPreloadMode && $isCli) {
            return null;
        }

        return \sprintf('ffi.enable=%s restricts FFI to the CLI and preload scripts; set ffi.enable=1 for the "%s" SAPI.', $ffiEnable, \PHP_SAPI);
    }

    private function log(string $message): void
    {
        if (\function_exists('Rapira\log')) {
            \Rapira\log('[rapira-symfony] ' . $message);

            return;
        }

        error_log('[rapira-symfony] ' . $message);
    }
}
