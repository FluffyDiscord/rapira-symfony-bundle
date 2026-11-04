<?php

namespace FluffyDiscord\RapiraBundle\Profiling;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFailedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerResponseSentEvent;

readonly class XhprofRequestProfiler
{
    public function __construct(
        private string $outputDir,
    )
    {
    }

    public function onRequestReceived(WorkerRequestReceivedEvent $event): void
    {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }

    public function onResponseSent(WorkerResponseSentEvent $event): void
    {
        $path = $event->request->getPathInfo();
        $this->writeProfile($path);
    }

    public function onRequestFailed(WorkerRequestFailedEvent $event): void
    {
        $target = $event->request->target;
        $queryPosition = strpos($target, '?');
        $path = $queryPosition === false ? $target : substr($target, 0, $queryPosition);
        $this->writeProfile($path);
    }

    private function writeProfile(string $path): void
    {
        $data = xhprof_disable();

        $sanitizedPath = preg_replace('/[^A-Za-z0-9._-]+/', '_', $path) ?? '';
        $sanitizedPath = trim($sanitizedPath, '_');
        if ($sanitizedPath === '') {
            $sanitizedPath = 'root';
        }

        $fileName = uniqid('', true) . '.' . $sanitizedPath . '.xhprof';
        $filePath = $this->outputDir . '/' . $fileName;

        file_put_contents($filePath, serialize($data));
    }
}
