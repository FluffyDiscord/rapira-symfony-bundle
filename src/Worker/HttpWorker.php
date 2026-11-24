<?php

namespace FluffyDiscord\RapiraBundle\Worker;

use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFailedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFinishedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RapiraBundle\ErrorHandler\MinimalErrorPage;
use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactoryInterface;
use FluffyDiscord\RapiraBundle\Writer\BinaryFileResponseWriter;
use FluffyDiscord\RapiraBundle\Writer\ResponseWriter;
use Rapira\Exception\RapiraThrowable;
use Rapira\Http\Exchange;
use Sentry\State\HubInterface as SentryHubInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\RebootableInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpWorker
{
    private ?Request $requestInFlight = null;

    public function __construct(
        private readonly KernelInterface            $kernel,
        private EventDispatcherInterface            $eventDispatcher,
        private readonly bool                       $debug,
        private readonly DispatcherInterface        $dispatcher,
        private readonly SymfonyRequestFactoryInterface $requestFactory,
        private ?ServicesResetterInterface          $servicesResetter = null,
        private readonly ?SentryHubInterface        $sentryHub = null,
    )
    {
    }

    public function start(): void
    {
        ignore_user_abort(true);
        register_shutdown_function($this->removeUploadsOfRequestInFlight(...));

        $pid = getmypid();
        $this->log(sprintf('rapira dispatcher worker started, pid %d', $pid === false ? 0 : $pid));

        $this->kernel->boot();
        $this->preloadResponseClasses();

        $this->eventDispatcher->dispatch(new WorkerBootingEvent());

        while (true) {
            $exchange = $this->dispatcher->receive();
            if ($exchange === null) {
                break;
            }

            $this->handleExchange($exchange);

            // @invariant Drop the last reference before the blocking receive(), so the host's
            // Work::__destruct can fail an exchange we never finalized.
            unset($exchange);
        }
    }

    private function log(string $message): void
    {
        if (\function_exists('Rapira\log')) {
            \Rapira\log('[rapira-symfony] ' . $message);

            return;
        }

        error_log('[rapira-symfony] ' . $message);
    }

    private function handleExchange(Exchange $exchange): void
    {
        $failed = false;
        $rapiraRequest = null;
        $request = null;
        $response = null;
        $writer = null;
        $containerHandlingRequest = null;

        $outputBufferLevel = ob_get_level();
        ob_start();

        try {
            $this->sentryHub?->pushScope();

            // @invariant The previous request may have shut down or rebooted the kernel: getContainer()
            // throws until it boots, and the bound services must follow the new container.
            $this->kernel->boot();
            $this->rebindToCurrentContainer();

            $rapiraRequest = $exchange->getRequest();
            $this->eventDispatcher->dispatch(new WorkerRequestReceivedEvent($rapiraRequest));

            $request = $this->requestFactory->createRequest($rapiraRequest);
            $this->requestInFlight = $request;
            $containerHandlingRequest = $this->kernel->getContainer();
            $response = $this->kernel->handle($request);

            $writer = $this->createWriter($response, $request);
            $writer->write($exchange);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }

            $this->eventDispatcher->dispatch(new WorkerResponseSentEvent($request, $response));
        } catch (RapiraThrowable $throwable) {
            $this->log('exchange closed by host: ' . (string) $throwable);
        } catch (\Throwable $throwable) {
            if ($rapiraRequest !== null) {
                $this->eventDispatcher->dispatch(new WorkerRequestFailedEvent($rapiraRequest, $throwable));
            }

            $this->sentryHub?->captureException($throwable);
            $this->log((string) $throwable);

            $headAlreadyWritten = $writer?->isHeadWritten() ?? false;
            if (!$headAlreadyWritten) {
                $this->writeErrorResponse($exchange, $throwable);
            }

            $failed = true;
        } finally {
            $this->dropStrayOutput($outputBufferLevel);

            $this->removeUploadsOfRequestInFlight();
            $this->requestInFlight = null;

            $this->eventDispatcher->dispatch(new WorkerRequestFinishedEvent($rapiraRequest));
            $this->servicesResetter?->reset();

            if ($request !== null) {
                $this->breakSessionFactoryCycle($request);
            }

            $this->releaseHttpCacheState($containerHandlingRequest, $request, $response);
            $this->releaseRequestFromErrorHandler();

            $isRebootable = $this->kernel instanceof RebootableInterface;
            if ($failed && $isRebootable) {
                $this->kernel->reboot(null);
            }

            $this->sentryHub?->getClient()?->flush();
            $this->sentryHub?->popScope();
        }
    }

    private function rebindToCurrentContainer(): void
    {
        $container = $this->kernel->getContainer();

        $servicesResetter = $container->has('services_resetter') ? $container->get('services_resetter') : null;
        if ($servicesResetter instanceof ServicesResetterInterface) {
            $this->servicesResetter = $servicesResetter;
        }

        $eventDispatcher = $container->has('event_dispatcher') ? $container->get('event_dispatcher') : null;
        if ($eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher = $eventDispatcher;
        }
    }

    private function releaseHttpCacheState(
        ?ContainerInterface $container,
        ?Request            $request,
        ?Response           $response,
    ): void
    {
        if ($container === null) {
            return;
        }

        $isHttpCacheBuilt = $container->initialized('http_cache');
        if (!$isHttpCacheBuilt) {
            return;
        }

        $httpCache = $container->get('http_cache');
        if (!$httpCache instanceof HttpCache) {
            return;
        }

        $httpCache->getStore()->cleanup();

        $wasHandled = $request !== null && $response !== null && $httpCache->getTraces() !== [];
        if ($wasHandled) {
            $request->initialize();
            $httpCache->getRequest()->initialize();
        }
    }

    private function dropStrayOutput(int $outputBufferLevel): void
    {
        while (ob_get_level() > $outputBufferLevel) {
            $strayOutput = ob_get_clean();
            $hasStrayOutput = \is_string($strayOutput) && $strayOutput !== '';
            if ($hasStrayOutput) {
                $this->log('dropped output printed outside the response: ' . $strayOutput);
            }
        }
    }

    private function removeUploadsOfRequestInFlight(): void
    {
        if ($this->requestInFlight !== null) {
            $this->removeUploadedFiles($this->requestInFlight);
        }
    }

    private function removeUploadedFiles(Request $request): void
    {
        $files = $request->files->all();
        array_walk_recursive($files, static function (mixed $file): void {
            if (!$file instanceof UploadedFile) {
                return;
            }

            $path = $file->getPathname();
            $isFileLeft = is_file($path);
            if ($isFileLeft) {
                unlink($path);
            }
        });
    }

    private function breakSessionFactoryCycle(Request $request): void
    {
        $hasPendingFactory = $request->hasSession() && !$request->hasSession(true);
        if (!$hasPendingFactory) {
            return;
        }

        $request->setSessionFactory(fn (): never => throw new SessionNotFoundException('The session is gone because the request has finished.'));
    }

    private function releaseRequestFromErrorHandler(): void
    {
        $exceptionHandler = get_exception_handler();
        $isSymfonyHandler = \is_array($exceptionHandler) && $exceptionHandler[0] instanceof ErrorHandler;
        if ($isSymfonyHandler) {
            $exceptionHandler[0]->setExceptionHandler(null);
        }
    }

    private function createWriter(Response $response, Request $request): ResponseWriter
    {
        $keepContentLength = $request->getMethod() === Request::METHOD_HEAD;

        if ($response instanceof BinaryFileResponse) {
            return new BinaryFileResponseWriter($response, $keepContentLength);
        }

        return new ResponseWriter($response, $keepContentLength);
    }

    private function writeErrorResponse(Exchange $exchange, \Throwable $throwable): void
    {
        try {
            if ($this->debug) {
                $renderer = new HtmlErrorRenderer(true);
                $flattened = $renderer->render($throwable);
                $html = $flattened->getAsString();
                $exchange->writeHead(500, ['content-type' => ['text/html; charset=utf-8']]);
                $exchange->writeBody($html, true);

                return;
            }

            $exchange->writeHead(500, []);
            $exchange->writeBody('', true);
        } catch (RapiraThrowable) {
        } catch (\Throwable) {
            $this->writeMinimalErrorPage($exchange);
        }
    }

    private function writeMinimalErrorPage(Exchange $exchange): void
    {
        try {
            $html = MinimalErrorPage::render(500, null);
            $exchange->writeHead(500, ['content-type' => ['text/html; charset=utf-8']]);
            $exchange->writeBody($html, true);
        } catch (\Throwable) {
        }
    }

    // @mago-ignore analysis:unused-statement(3)
    private function preloadResponseClasses(): void
    {
        class_exists(StreamedResponse::class);
        class_exists(StreamedJsonResponse::class);
        class_exists(BinaryFileResponse::class);
    }
}
