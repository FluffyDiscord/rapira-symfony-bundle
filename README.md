# fluffydiscord/rapira-symfony-bundle

Run a Symfony app as a resident worker under [Rapira](https://github.com/rapira-rs/rapira), classic or **dispatcher mode**.

HTTP worker only. No Jobs / KV / Centrifugo / Temporal (waiting for Rapira).

## Requirements

- PHP >= 8.4
- Rapira pool with `mode = "dispatcher"` for the resident worker (classic mode uses the standard runtime)
- `symfony/*` `^7.4 || ^8`

## Installation

```bash
composer require fluffydiscord/rapira-symfony-bundle
```

Register the bundle (`config/bundles.php`):

```php
FluffyDiscord\RapiraBundle\FluffyDiscordRapiraBundle::class => ['all' => true],
```

Add the kernel trait (`src/Kernel.php`) so a rebooted kernel keeps the properties the worker preserves:

```php
use FluffyDiscord\RapiraBundle\Kernel\RapiraMicroKernelTrait;

class Kernel extends BaseKernel
{
    use RapiraMicroKernelTrait;
}
```

`worker.php` ships vendored — nothing to write. It boots `.env` with `usePutenv()` (no `symfony/runtime`) and hands the kernel to the worker. `public/index.php` and `bin/console` stay as the skeleton ships them (classic mode + console).

Copy the sample `rapira.toml` to the project root (Rapira reads it from there) and edit your copy:

```bash
cp vendor/fluffydiscord/rapira-symfony-bundle/rapira.toml rapira.toml
```

```toml
[http]
listen = "0.0.0.0:8000"
max_body_size_mb = 20

[http.uploads]
dir = "/tmp"
max_file_size_mb = 1
max_files = 20

[pool]
entrypoint = "vendor/fluffydiscord/rapira-symfony-bundle/worker.php"
mode = "dispatcher"
processes = 4

[log]
level = "info"
format = "json"
```

Keep `entrypoint` pointing at the vendored `worker.php`; change everything else freely. Kernel class defaults to `App\Kernel`; override with `APP_KERNEL_CLASS`.

**dev → classic, prod → dispatcher.** Classic runs the standard runtime per request (`rapira serve --classic public/index.php`), so code changes are picked up with no worker restart. Dispatcher runs the resident worker (`worker.php`). The console always uses the standard runtime (`php bin/console`).

## Configuration

```yaml
# config/packages/rapira.yaml
rapira:
    warmup:
        enabled: true            # boot-time warmers + learned-manifest recorder
        learn: true
        learn_requests: 30       # stop recording after N responses per worker
        manifest_path: ~         # default: %kernel.cache_dir%/rapira/warmup.manifest.json
    doctrine:
        preconnect: true         # open Postgres connections at worker boot
    profiling:
        xhprof:
            enabled: false       # false (default) | true | "auto" (ext-xhprof loaded and kernel.debug)
            output_dir: ~        # default: ini xhprof.output_dir, then sys_get_temp_dir()/xhprof
    vips:                        # bound libvips's process-global cache at worker boot
        enabled: auto            # auto - true if jcupitt/vips installed
        max_operations: 50
        max_memory_mb: 50
        max_files: 20
```

## Dispatcher-mode notes

- **Streaming.** `StreamedResponse` / `StreamedJsonResponse` stream progressively, carry `X-Accel-Buffering: no`. Callbacks must `echo` (or `setChunks()`); a `yield`-based callback is **not** run by `sendContent()`.
- **Superglobals empty.** Read from the injected `Request`, not `$_GET`/`$_SERVER`. `echo`/`header()` output is discarded — respond through the Response.
- **Logging.** `error_log()` is discarded; the bundle logs via `\Rapira\log()` (the `app` target).
- **Sessions** work unchanged (native or `PdoSessionHandler`); `services_resetter` clears per-request state.
- **Uploads** are parsed host-side (`[http.uploads]`), mapped to Symfony `UploadedFile`s. `move()` renames the host temp file before the exchange finalizes.

## Events

`WorkerBootingEvent`, `WorkerRequestReceivedEvent` (`Rapira\Http\Request`), `WorkerResponseSentEvent` (Symfony request + response), `WorkerRequestFailedEvent` (`Rapira\Http\Request` + throwable).

## Testing

```bash
tests/docker-qa.sh                # PHPStan (level max) + PHPUnit, in a container
tests/docker/run-integration.sh   # IT-101..IT-107 against the real Rapira binary
```
