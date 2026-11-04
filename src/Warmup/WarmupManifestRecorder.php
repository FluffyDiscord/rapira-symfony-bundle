<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerResponseSentEvent;
use Psr\Log\LoggerInterface;

/**
 * @invariant Record the full symbol and file sets, never a delta from a baseline:
 *            a baseline taken at the first response misses that request's cold-load classes.
 */
class WarmupManifestRecorder
{
    private int $respondedRequests = 0;

    private int $lastSymbolCount = 0;

    private int $lastFileCount = 0;

    private bool $done = false;

    public function __construct(
        private readonly WarmupManifestStorage $storage,
        private readonly string                $cacheDir,
        private readonly int                   $learnRequests,
        private readonly ?LoggerInterface      $logger = null,
    )
    {
    }

    public function __invoke(WorkerResponseSentEvent $event): void
    {
        if ($this->done) {
            return;
        }

        try {
            $this->record();
        } catch (\Throwable $throwable) {
            $this->logger?->error('Rapira warmup: recording failed for this response; will retry on the next one.', [
                'exception' => $throwable,
            ]);
        }

        $this->respondedRequests++;
        if ($this->respondedRequests >= $this->learnRequests) {
            $this->done = true;
        }
    }

    private function record(): void
    {
        $currentSymbols = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
        $currentFiles = get_included_files();

        if (count($currentSymbols) === $this->lastSymbolCount && count($currentFiles) === $this->lastFileCount) {
            return;
        }

        $cacheFiles = array_values(array_filter(
            $currentFiles,
            fn(string $file) => str_starts_with($file, $this->cacheDir),
        ));

        ob_start();
        try {
            $written = $this->storage->write($currentSymbols, $cacheFiles);
        } finally {
            ob_end_clean();
        }

        if ($written) {
            $this->lastSymbolCount = count($currentSymbols);
            $this->lastFileCount = count($currentFiles);
        }
    }
}
