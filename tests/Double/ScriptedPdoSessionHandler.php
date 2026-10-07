<?php

namespace FluffyDiscord\RapiraBundle\Tests\Double;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class ScriptedPdoSessionHandler extends PdoSessionHandler
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param list<string> $failingMethods
     */
    public function __construct(
        private readonly array $failingMethods = [],
    )
    {
        parent::__construct();
    }

    public function open(string $savePath, string $sessionName): bool
    {
        $this->record('open', $savePath . ',' . $sessionName);

        return true;
    }

    public function validateId(#[\SensitiveParameter] string $sessionId): bool
    {
        $this->record('validateId', $sessionId);

        return true;
    }

    public function read(#[\SensitiveParameter] string $sessionId): string
    {
        $this->record('read', $sessionId);

        return 'data-of-' . $sessionId;
    }

    public function write(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $this->record('write', $sessionId . ',' . $data);

        return true;
    }

    public function destroy(#[\SensitiveParameter] string $sessionId): bool
    {
        $this->record('destroy', $sessionId);

        return true;
    }

    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $this->record('updateTimestamp', $sessionId);

        return true;
    }

    public function gc(int $maxlifetime): int|false
    {
        $this->record('gc', (string) $maxlifetime);

        return 0;
    }

    public function close(): bool
    {
        $this->record('close', '');

        return true;
    }

    private function record(string $method, string $arguments): void
    {
        $this->calls[] = $method . '(' . $arguments . ')';

        $isFailing = \in_array($method, $this->failingMethods, true);
        if ($isFailing) {
            throw new \PDOException('server closed the connection unexpectedly');
        }
    }
}
