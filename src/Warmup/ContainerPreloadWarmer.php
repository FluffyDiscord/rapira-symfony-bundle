<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Dumper\Preloader;

/**
 * @invariant Parse the dumped preload file, never include it: it returns early under
 *            cli, phpdbg and embed, and the worker's SAPI name is Rapira's to choose.
 */
class ContainerPreloadWarmer implements WorkerWarmerInterface
{
    public function __construct(
        private readonly string                $buildDir,
        private readonly WarmupManifestStorage $storage,
        private readonly ?LoggerInterface      $logger = null,
    )
    {
    }

    public function warmup(): void
    {
        if ($this->storage->exists()) {
            $this->logger?->debug('Rapira warmup: learned manifest present; container preload fallback skipped.');

            return;
        }

        $classes = $this->extractPreloadClasses();
        if ($classes === []) {
            $this->logger?->debug('Rapira warmup: no container preload file or no classes extracted; skipping.');

            return;
        }

        $this->preloadClasses($classes);
    }

    /**
     * @param list<string> $classes
     */
    protected function preloadClasses(array $classes): void
    {
        Preloader::preload($classes);
    }

    /**
     * @return list<string>
     */
    private function extractPreloadClasses(): array
    {
        $preloadFiles = glob($this->buildDir . '/*.preload.php');
        if ($preloadFiles === false || $preloadFiles === []) {
            return [];
        }

        if (count($preloadFiles) > 1) {
            $this->logger?->debug('Rapira warmup: multiple preload files in the build dir; using "{file}".', [
                'file' => $preloadFiles[0],
            ]);
        }

        $source = file_get_contents($preloadFiles[0]);
        if ($source === false) {
            return [];
        }

        $matches = [];
        if (preg_match_all("/^\\\$classes\\[\\] = '([^']+)';$/m", $source, $matches) === false) {
            return [];
        }

        return array_values($matches[1]);
    }
}
