<?php

namespace FluffyDiscord\RapiraBundle\DependencyInjection;

use FluffyDiscord\RapiraBundle\Doctrine\DoctrinePreconnectListener;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerBootingEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestFailedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerRequestReceivedEvent;
use FluffyDiscord\RapiraBundle\Event\Worker\WorkerResponseSentEvent;
use FluffyDiscord\RapiraBundle\Profiling\XhprofRequestProfiler;
use FluffyDiscord\RapiraBundle\Vips\VipsCacheLimiter;
use FluffyDiscord\RapiraBundle\Warmup\ContainerPreloadWarmer;
use FluffyDiscord\RapiraBundle\Warmup\DoctrineWarmer;
use FluffyDiscord\RapiraBundle\Warmup\EventListenersWarmer;
use FluffyDiscord\RapiraBundle\Warmup\FormRegistryWarmer;
use FluffyDiscord\RapiraBundle\Warmup\LearnedManifestWarmer;
use FluffyDiscord\RapiraBundle\Warmup\RouterWarmer;
use FluffyDiscord\RapiraBundle\Warmup\TwigRuntimesWarmer;
use FluffyDiscord\RapiraBundle\Warmup\WarmupManifestRecorder;
use FluffyDiscord\RapiraBundle\Warmup\WarmupManifestStorage;
use FluffyDiscord\RapiraBundle\Warmup\WorkerWarmerInterface;
use FluffyDiscord\RapiraBundle\Warmup\WorkerWarmupRunner;
use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class FluffyDiscordRapiraExtension extends Extension
{
    private const string WORKER_WARMER_TAG = 'fluffy_discord.rapira.worker_warmer';

    public function getAlias(): string
    {
        return 'rapira';
    }

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{http: array{lazy_boot: bool}, warmup: array{enabled: bool, learn: bool, learn_requests: int, manifest_path: ?string}, doctrine: array{preconnect: bool}, profiling: array{xhprof: array{enabled: string|bool, output_dir: ?string}}, vips: array{enabled: string|bool, max_operations: int, max_memory_mb: int, max_files: int}} $config */
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        $httpWorker = $container->getDefinition(HttpWorker::class);
        $httpWorker->replaceArgument(0, $config['http']['lazy_boot']);

        if ($config['warmup']['enabled']) {
            $this->registerWarmup($container, $config['warmup']);
        }

        $dbalInstalled = class_exists(\Doctrine\DBAL\Connection::class);
        if ($dbalInstalled && $config['doctrine']['preconnect']) {
            $this->registerDoctrinePreconnect($container);
        }

        $this->registerXhprofProfiling($container, $config['profiling']['xhprof']);

        $vipsEnabled = $this->shouldRegisterVipsCacheLimiter($config['vips']['enabled']);
        if ($vipsEnabled) {
            $this->registerVipsCacheLimiter($container, $config['vips']);
        }
    }

    /**
     * @param array{enabled: string|bool, max_operations: int, max_memory_mb: int, max_files: int} $vipsConfig
     */
    private function registerVipsCacheLimiter(ContainerBuilder $container, array $vipsConfig): void
    {
        $maxMemoryBytes = $vipsConfig['max_memory_mb'] * 1024 * 1024;

        $definition = new Definition(VipsCacheLimiter::class, [
            $vipsConfig['max_operations'],
            $maxMemoryBytes,
            $vipsConfig['max_files'],
        ]);
        $definition->addTag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke']);
        $container->setDefinition(VipsCacheLimiter::class, $definition);
    }

    private function shouldRegisterVipsCacheLimiter(string|bool $enabled): bool
    {
        if ($enabled === false) {
            return false;
        }

        // Both "auto" and forced "true" need php-vips present; the limiter calls into it at boot.
        return class_exists(\Jcupitt\Vips\Config::class);
    }

    /**
     * @param array{enabled: bool, learn: bool, learn_requests: int, manifest_path: ?string} $warmupConfig
     */
    private function registerWarmup(ContainerBuilder $container, array $warmupConfig): void
    {
        $container
            ->registerForAutoconfiguration(WorkerWarmerInterface::class)
            ->addTag(self::WORKER_WARMER_TAG);

        $manifestPath = $warmupConfig['manifest_path'] ?? '%kernel.cache_dir%/rapira/warmup.manifest.json';

        $storage = new Definition(WarmupManifestStorage::class, [
            $manifestPath,
            new Reference('parameter_bag', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $container->setDefinition(WarmupManifestStorage::class, $storage);

        $learnedManifestWarmer = new Definition(LearnedManifestWarmer::class, [
            new Reference(WarmupManifestStorage::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $learnedManifestWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 64]);
        $container->setDefinition(LearnedManifestWarmer::class, $learnedManifestWarmer);

        $containerPreloadWarmer = new Definition(ContainerPreloadWarmer::class, [
            '%kernel.build_dir%',
            new Reference(WarmupManifestStorage::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $containerPreloadWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 48]);
        $container->setDefinition(ContainerPreloadWarmer::class, $containerPreloadWarmer);

        $routerWarmer = new Definition(RouterWarmer::class, [
            new Reference('router.default', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $routerWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 32]);
        $container->setDefinition(RouterWarmer::class, $routerWarmer);

        $doctrineRegistryExists = interface_exists(\Doctrine\Persistence\ManagerRegistry::class);
        if ($doctrineRegistryExists) {
            $doctrineWarmer = new Definition(DoctrineWarmer::class, [
                new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
            $doctrineWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 32]);
            $container->setDefinition(DoctrineWarmer::class, $doctrineWarmer);
        }

        $eventListenersWarmer = new Definition(EventListenersWarmer::class, [
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $eventListenersWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 16]);
        $container->setDefinition(EventListenersWarmer::class, $eventListenersWarmer);

        $formRegistryExists = interface_exists(\Symfony\Component\Form\FormRegistryInterface::class);
        if ($formRegistryExists) {
            $formRegistryWarmer = new Definition(FormRegistryWarmer::class, [
                new TaggedIteratorArgument('form.type'),
                new Reference('form.registry', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
            $formRegistryWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 16]);
            $container->setDefinition(FormRegistryWarmer::class, $formRegistryWarmer);
        }

        $twigRuntimesWarmer = new Definition(TwigRuntimesWarmer::class, [
            new TaggedIteratorArgument('twig.runtime'),
        ]);
        $twigRuntimesWarmer->addTag(self::WORKER_WARMER_TAG, ['priority' => 16]);
        $container->setDefinition(TwigRuntimesWarmer::class, $twigRuntimesWarmer);

        $runner = new Definition(WorkerWarmupRunner::class, [
            new TaggedIteratorArgument(self::WORKER_WARMER_TAG),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '%kernel.runtime_mode.worker%',
        ]);
        $runner->addTag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke', 'priority' => 128]);
        $container->setDefinition(WorkerWarmupRunner::class, $runner);

        if ($warmupConfig['learn']) {
            $recorder = new Definition(WarmupManifestRecorder::class, [
                new Reference(WarmupManifestStorage::class),
                '%kernel.cache_dir%',
                $warmupConfig['learn_requests'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '%kernel.runtime_mode.worker%',
            ]);
            $recorder->addTag('kernel.event_listener', ['event' => WorkerResponseSentEvent::class, 'method' => '__invoke']);
            $container->setDefinition(WarmupManifestRecorder::class, $recorder);
        }
    }

    private function registerDoctrinePreconnect(ContainerBuilder $container): void
    {
        $definition = new Definition(DoctrinePreconnectListener::class, [
            new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('kernel.event_listener', ['event' => WorkerBootingEvent::class, 'method' => '__invoke']);
        $container->setDefinition(DoctrinePreconnectListener::class, $definition);
    }

    /**
     * @param array{enabled: string|bool, output_dir: ?string} $xhprofConfig
     */
    private function registerXhprofProfiling(ContainerBuilder $container, array $xhprofConfig): void
    {
        $shouldRegister = $this->shouldRegisterXhprof($container, $xhprofConfig['enabled']);
        if (!$shouldRegister) {
            return;
        }

        $outputDir = $xhprofConfig['output_dir'] ?? $this->getDefaultXhprofOutputDir();

        $profiler = new Definition(XhprofRequestProfiler::class, [$outputDir]);
        $profiler->addTag('kernel.event_listener', ['event' => WorkerRequestReceivedEvent::class, 'method' => 'onRequestReceived']);
        $profiler->addTag('kernel.event_listener', ['event' => WorkerResponseSentEvent::class, 'method' => 'onResponseSent']);
        $profiler->addTag('kernel.event_listener', ['event' => WorkerRequestFailedEvent::class, 'method' => 'onRequestFailed']);
        $container->setDefinition(XhprofRequestProfiler::class, $profiler);
    }

    private function shouldRegisterXhprof(ContainerBuilder $container, string|bool $enabled): bool
    {
        if ($enabled === false) {
            return false;
        }

        $xhprofLoaded = extension_loaded('xhprof');
        if (!$xhprofLoaded) {
            return false;
        }

        if ($enabled === true) {
            return true;
        }

        $debug = (bool) $container->getParameter('kernel.debug');

        return $debug;
    }

    private function getDefaultXhprofOutputDir(): string
    {
        $iniOutputDir = ini_get('xhprof.output_dir');
        $iniOutputDirUsable = is_string($iniOutputDir) && $iniOutputDir !== '';
        if ($iniOutputDirUsable) {
            return $iniOutputDir;
        }

        return sys_get_temp_dir() . '/xhprof';
    }
}
