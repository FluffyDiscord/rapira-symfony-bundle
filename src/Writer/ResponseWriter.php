<?php

namespace FluffyDiscord\RapiraBundle\Writer;

use Rapira\Exception\RapiraThrowable;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a Symfony Response to a Rapira exchange through Symfony's own output contract.
 * A plain response is one write; a StreamedResponse (and its subclasses, e.g.
 * StreamedJsonResponse) is streamed by running sendContent() under an output-buffer handler
 * that forwards every chunk to the exchange as it is produced.
 */
class ResponseWriter
{
    protected bool $headWritten = false;

    protected ?RapiraThrowable $hostError = null;

    public function __construct(
        protected readonly Response $response,
        protected readonly bool     $keepContentLength,
    )
    {
    }

    public function isHeadWritten(): bool
    {
        return $this->headWritten;
    }

    public function write(Exchange $exchange): void
    {
        $this->writeHead($exchange);
        $this->writeBody($exchange);
    }

    protected function writeHead(Exchange $exchange): void
    {
        $status = $this->response->getStatusCode();
        $statusIsValid = $status >= 100 && $status <= 599;
        if (!$statusIsValid) {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        $headers = $this->buildHeaders();
        $exchange->writeHead($status, $headers);
        $this->headWritten = true;
    }

    protected function writeBody(Exchange $exchange): void
    {
        if ($this->response instanceof StreamedResponse) {
            $this->streamBody($exchange);

            return;
        }

        $content = (string) $this->response->getContent();
        $exchange->writeBody($content, true);
    }

    protected function streamBody(Exchange $exchange): void
    {
        $baseLevel = ob_get_level();

        $forward = function (string $buffer, int $phase) use ($exchange): string {
            $isDiscardingBuffer = ($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0;
            $hasBufferToSend = $buffer !== '' && !$isDiscardingBuffer && $this->hostError === null;
            if ($hasBufferToSend) {
                try {
                    $exchange->writeBody($buffer, false);
                    $exchange->flush();
                } catch (RapiraThrowable $throwable) {
                    $this->hostError = $throwable;
                }
            }

            return '';
        };

        ob_start($forward, 1);

        try {
            $this->response->sendContent();

            if ($this->hostError === null) {
                ob_end_flush();
            }

            if ($this->hostError === null) {
                $exchange->writeBody('', true);
            }
        } finally {
            while (ob_get_level() > $baseLevel) {
                ob_end_clean();
            }
        }

        if ($this->hostError !== null) {
            throw $this->hostError;
        }
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    protected function buildHeaders(): array
    {
        /** @var array<string, list<string>> $allHeaders */
        $allHeaders = $this->response->headers->allPreserveCase();

        $headers = [];
        foreach ($allHeaders as $name => $values) {
            if ($name === '') {
                continue;
            }

            $lowerName = strtolower($name);

            if ($lowerName === 'transfer-encoding') {
                continue;
            }

            if ($lowerName === 'content-length' && !$this->keepContentLength) {
                continue;
            }

            $headers[$name] = $values;
        }

        if ($this->response instanceof StreamedResponse) {
            $headers['X-Accel-Buffering'] = ['no'];
        }

        return $headers;
    }
}
