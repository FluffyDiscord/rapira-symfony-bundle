<?php

declare(strict_types=1);

/*
 * The dispatcher entrypoint shipped with the bundle. Point rapira.toml at it:
 *
 *   [pool]
 *   entrypoint = "vendor/fluffydiscord/rapira-symfony-bundle/worker.php"
 *   mode = "dispatcher"
 *
 * It bootstraps the app itself (no symfony/runtime), so $_SERVER['SCRIPT_FILENAME'] — which
 * dispatcher mode leaves unset — never matters, and usePutenv() keeps prod env values alive
 * across PHP's mid-request $_ENV re-import. The kernel class defaults to App\Kernel and can be
 * overridden with the APP_KERNEL_CLASS environment variable.
 */

use FluffyDiscord\RapiraBundle\Worker\HttpWorker;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\KernelInterface;

// This file lives at <project>/vendor/fluffydiscord/rapira-symfony-bundle/worker.php.
$projectDir = \dirname(__DIR__, 3);

require $projectDir . '/vendor/autoload.php';

(new Dotenv())->usePutenv()->bootEnv($projectDir . '/.env');

// Drives Symfony's kernel.runtime_mode.* parameters (resolved at runtime from this env var),
// which gate the bundle's boot-time warmup on a persistent worker. Must be set before boot.
$_SERVER['APP_RUNTIME_MODE'] = 'web=1&worker=1';

$kernelClass = $_SERVER['APP_KERNEL_CLASS'] ?? $_ENV['APP_KERNEL_CLASS'] ?? 'App\Kernel';
assert(is_string($kernelClass));

$kernel = new $kernelClass($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
assert($kernel instanceof KernelInterface);
$kernel->boot();

$worker = $kernel->getContainer()->get(HttpWorker::class);
assert($worker instanceof HttpWorker);
$worker->start();
