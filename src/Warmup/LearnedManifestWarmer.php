<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Dumper\Preloader;

/**
 * @invariant Preload classes through Symfony's Preloader, never opcache_compile_file():
 *            that early-binds them and later fatals with "Cannot redeclare" on multi-class files.
 */
class LearnedManifestWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private readonly WarmupManifestStorage $storage,
        private readonly ?LoggerInterface      $logger = null,
    )
    {
    }

    public function warmup(): void
    {
        $manifest = $this->storage->read();
        if ($manifest === null) {
            $this->logger?->debug('Rapira warmup: no learned manifest yet; skipping.');

            return;
        }

        $this->preloadClasses($manifest['classes']);

        if (!$this->supportsFileCompilation()) {
            return;
        }

        $alreadyIncluded = array_flip(get_included_files());

        foreach ($manifest['files'] as $file) {
            if (isset($alreadyIncluded[$file]) || !is_file($file)) {
                continue;
            }

            $this->compileFile($file);
        }
    }

    protected function compileFile(string $file): void
    {
        try {
            @opcache_compile_file($file);
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<string> $classes
     */
    protected function preloadClasses(array $classes): void
    {
        Preloader::preload($classes);
    }

    private function supportsFileCompilation(): bool
    {
        if (!function_exists('opcache_compile_file')) {
            $this->logger?->debug('Rapira warmup: opcache_compile_file unavailable; skipping cache-dir file compilation.');

            return false;
        }

        // @invariant Compiling volatile cache-pool files while opcache.file_cache is active slows first requests.
        if ($this->getFileCacheIni() !== '') {
            $this->logger?->debug('Rapira warmup: opcache.file_cache active; skipping cache-dir file compilation.');

            return false;
        }

        return true;
    }

    protected function getFileCacheIni(): string
    {
        return (string) ini_get('opcache.file_cache');
    }
}
