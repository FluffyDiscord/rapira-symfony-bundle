<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use Psr\Log\LoggerInterface;

/** See docs/specs/worker-warmup.md ADR-2, ADR-5. */
readonly class WorkerWarmupRunner
{
    /**
     * In classic mode Rapira runs a fresh process for every request, so nothing warmed
     * survives to a second request and the boot-time replay only adds latency. Worse,
     * compiling cached Twig templates early-binds their classes, which makes Twig skip its
     * freshness check and serve stale templates in development. The runner therefore no-ops
     * unless the kernel runs in persistent worker mode (kernel.runtime_mode.worker).
     *
     * @param iterable<WorkerWarmerInterface> $warmers priority-ordered tagged iterator
     */
    public function __construct(
        private iterable         $warmers,
        private ?LoggerInterface $logger = null,
        private bool             $persistentWorker = true,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        if (!$this->persistentWorker) {
            $this->logger?->debug('Rapira warmup: skipped, the worker pool runs in classic mode (one process per request).');

            return;
        }

        $totalStart = microtime(true);
        $warmerCount = 0;

        // A warmer's stray output (echo, a PHP warning with display_errors) is discarded in
        // dispatcher mode anyway (output never reaches a client, S-23); capture it so it can
        // be logged rather than lost.
        ob_start();

        try {
            // The foreach itself can throw: tagged-iterator advancement lazily
            // instantiates each warmer service, and a failing constructor anywhere in a
            // warmer's dependency graph surfaces at the loop statement, not inside
            // warmup(). An escape here would kill the worker before the serve loop and
            // crash-loop the pool — the exact failure class this feature removed.
            try {
                foreach ($this->warmers as $warmer) {
                    $warmerStart = microtime(true);

                    try {
                        $warmer->warmup();
                    } catch (\Throwable $throwable) {
                        $this->logger?->error('Rapira warmup: warmer "{warmer}" failed; continuing.', [
                            'warmer' => $warmer::class,
                            'exception' => $throwable,
                        ]);
                    }

                    $warmerCount++;

                    $this->logger?->debug('Rapira warmup: "{warmer}" took {duration_ms}ms.', [
                        'warmer' => $warmer::class,
                        'duration_ms' => round((microtime(true) - $warmerStart) * 1000, 1),
                    ]);
                }
            } catch (\Throwable $throwable) {
                $this->logger?->error('Rapira warmup: aborted while instantiating a warmer; remaining warmers skipped.', [
                    'exception' => $throwable,
                ]);
            }
        } finally {
            $capturedOutput = ob_get_clean();

            if ($capturedOutput !== false && $capturedOutput !== '') {
                $this->logger?->warning('Rapira warmup: discarded {bytes} bytes of stray output (the dispatcher never sends it to a client).', [
                    'bytes' => strlen($capturedOutput),
                ]);
            }
        }

        $this->logger?->info('Rapira warmup: {count} warmers finished in {duration_ms}ms.', [
            'count' => $warmerCount,
            'duration_ms' => round((microtime(true) - $totalStart) * 1000, 1),
        ]);
    }
}
