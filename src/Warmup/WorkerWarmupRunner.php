<?php

namespace FluffyDiscord\RapiraBundle\Warmup;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use Psr\Log\LoggerInterface;

readonly class WorkerWarmupRunner
{
    /**
     * @param iterable<WorkerWarmerInterface> $warmers priority-ordered tagged iterator
     */
    public function __construct(
        private iterable         $warmers,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    public function __invoke(WorkerBootingEvent $event): void
    {
        $totalStart = microtime(true);
        $warmerCount = 0;

        ob_start();

        try {
            // @invariant Advancing the tagged iterator instantiates warmers, so a failing constructor throws
            // at the foreach. An escape kills the worker before the serve loop and crash-loops the pool.
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
