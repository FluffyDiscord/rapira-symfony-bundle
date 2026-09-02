<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exchange;
use Rapira\Http\Request;

/**
 * A fake Exchange that records the verb calls a writer or the worker makes. It can be told
 * to throw a WorkDiscardedException on the Nth body write, to exercise the host-closed path.
 */
class RecordingExchange implements Exchange
{
    /** @var list<array{status: int, headers: array<string, list<string>>}> */
    public array $heads = [];

    /** @var list<array{content: string, eos: bool}> */
    public array $bodyWrites = [];

    /** @var list<array{path: string, offset: int, length: ?int, eos: bool}> */
    public array $fileSends = [];

    public int $flushCount = 0;

    private bool $finalized = false;

    private int $bodyWriteCount = 0;

    public function __construct(
        private readonly Request $request,
        private readonly ?int    $throwWorkDiscardedOnBodyWrite = null,
        private readonly bool    $rejectSendFile = false,
    )
    {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function writeHead(int $status, array $headers = []): void
    {
        $this->heads[] = ['status' => $status, 'headers' => $headers];
    }

    public function writeBody(string $content, bool $eos = true): void
    {
        $this->bodyWriteCount++;
        if ($this->throwWorkDiscardedOnBodyWrite === $this->bodyWriteCount) {
            throw new WorkDiscardedException('host closed the exchange');
        }

        $this->bodyWrites[] = ['content' => $content, 'eos' => $eos];
        if ($eos) {
            $this->finalized = true;
        }
    }

    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        if ($this->rejectSendFile) {
            throw new FileNotSendableException('file is outside the sendfile root');
        }

        $this->fileSends[] = ['path' => $path, 'offset' => $offset, 'length' => $length, 'eos' => $eos];
        if ($eos) {
            $this->finalized = true;
        }
    }

    public function writeTrailers(array $trailers): void
    {
    }

    public function flush(): void
    {
        $this->flushCount++;
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function __destruct()
    {
    }
}
