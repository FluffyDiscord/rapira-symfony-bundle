<?php

namespace FluffyDiscord\RapiraBundle\Tests\Session;

use FluffyDiscord\RapiraBundle\Session\ReconnectingPdoSessionHandler;
use FluffyDiscord\RapiraBundle\Session\ReconnectingSessionHandlerFactory;
use FluffyDiscord\RapiraBundle\Tests\Double\ScriptedPdoSessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class ReconnectingPdoSessionHandlerTest extends TestCase
{
    /**
     * @var list<ScriptedPdoSessionHandler>
     */
    private array $freshHandlers = [];

    public function testHealthySessionUsesOneHandler(): void
    {
        $first = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first);

        $handler->open('/path', 'SID');
        $data = $handler->read('abc');
        $handler->write('abc', 'new');
        $handler->close();

        self::assertSame('data-of-abc', $data);
        self::assertSame(['open(/path,SID)', 'read(abc)', 'write(abc,new)', 'close()'], $first->calls);
        self::assertSame([], $this->freshHandlers);
    }

    public function testFailedReadClosesFailedHandlerAndRerunsOnFreshHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['read']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);

        $handler->open('/path', 'SID');
        $data = $handler->read('abc');

        self::assertSame('data-of-abc', $data);
        self::assertSame(['open(/path,SID)', 'read(abc)', 'close()'], $first->calls);
        self::assertSame(['open(/path,SID)', 'read(abc)'], $fresh->calls);
    }

    public function testFailedOpenIsRerunOnFreshHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['open']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);

        $opened = $handler->open('/path', 'SID');

        self::assertTrue($opened);
        self::assertSame(['open(/path,SID)', 'close()'], $first->calls);
        self::assertSame(['open(/path,SID)'], $fresh->calls);
    }

    public function testFailedWriteClosesFailedHandlerAndRerunsOnFreshHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['write']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);

        $handler->open('/path', 'SID');
        $handler->read('abc');
        $written = $handler->write('abc', 'new');

        self::assertTrue($written);
        self::assertSame(['open(/path,SID)', 'read(abc)', 'write(abc,new)', 'close()'], $first->calls);
        self::assertSame(['open(/path,SID)', 'write(abc,new)'], $fresh->calls);
    }

    public function testFailedDestroyIsRerunOnFreshHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['destroy']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);

        $handler->open('/path', 'SID');
        $handler->destroy('old');

        self::assertSame(['open(/path,SID)', 'destroy(old)'], $fresh->calls);
    }

    public function testFailedHandlerThatCannotCloseIsStillReplaced(): void
    {
        $first = new ScriptedPdoSessionHandler(['read', 'close']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);

        $handler->open('/path', 'SID');
        $data = $handler->read('abc');

        self::assertSame('data-of-abc', $data);
        self::assertSame(['open(/path,SID)', 'read(abc)', 'close()'], $first->calls);
        self::assertSame(['open(/path,SID)', 'read(abc)'], $fresh->calls);
    }

    public function testFailedCloseThrowsAndNextSessionUsesFreshHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['close']);
        $fresh = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $fresh);
        $handler->open('/path', 'SID');
        $handler->write('abc', 'new');

        try {
            $handler->close();
            self::fail('close() must not hide a lost commit.');
        } catch (\PDOException) {
        }

        $handler->open('/path', 'SID');
        $handler->read('abc');

        self::assertSame(['open(/path,SID)', 'write(abc,new)', 'close()', 'close()'], $first->calls);
        self::assertSame(['open(/path,SID)', 'read(abc)'], $fresh->calls);
    }

    public function testSecondFailurePropagatesAndClosesTheRetriedHandler(): void
    {
        $first = new ScriptedPdoSessionHandler(['read']);
        $retried = new ScriptedPdoSessionHandler(['read']);
        $next = new ScriptedPdoSessionHandler();
        $handler = $this->createHandler($first, $retried, $next);
        $handler->open('/path', 'SID');

        try {
            $handler->read('abc');
            self::fail('A second failure must propagate.');
        } catch (\PDOException) {
        }

        $handler->open('/path', 'SID');

        self::assertSame(['open(/path,SID)', 'read(abc)', 'close()'], $retried->calls);
        self::assertSame(['open(/path,SID)'], $next->calls);
    }

    public function testOverridesEveryStatefulPublicMethod(): void
    {
        $statelessMethods = ['__construct', 'create_sid', '__serialize', '__unserialize'];
        $parentMethods = new \ReflectionClass(PdoSessionHandler::class)->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($parentMethods as $parentMethod) {
            $isStateless = \in_array($parentMethod->getName(), $statelessMethods, true);
            if ($isStateless) {
                continue;
            }

            $method = new \ReflectionMethod(ReconnectingPdoSessionHandler::class, $parentMethod->getName());
            self::assertSame(
                ReconnectingPdoSessionHandler::class,
                $method->getDeclaringClass()->getName(),
                $parentMethod->getName() . '() would run on the unused parent handler',
            );
        }
    }

    public function testFactoryWrapsPdoHandler(): void
    {
        $handler = new ReconnectingSessionHandlerFactory()->createHandler(fn() => new ScriptedPdoSessionHandler());

        self::assertInstanceOf(ReconnectingPdoSessionHandler::class, $handler);
    }

    public function testFactoryReturnsOtherHandlersUnchanged(): void
    {
        $nullHandler = new NullSessionHandler();

        $handler = new ReconnectingSessionHandlerFactory()->createHandler(fn() => $nullHandler);

        self::assertSame($nullHandler, $handler);
    }

    private function createHandler(ScriptedPdoSessionHandler $first, ScriptedPdoSessionHandler ...$fresh): ReconnectingPdoSessionHandler
    {
        $this->freshHandlers = [];
        $queue = $fresh;

        $createHandler = function () use (&$queue): PdoSessionHandler {
            $next = array_shift($queue) ?? throw new \LogicException('No fresh handler scripted.');
            $this->freshHandlers[] = $next;

            return $next;
        };

        return new ReconnectingPdoSessionHandler($createHandler, $first);
    }
}
