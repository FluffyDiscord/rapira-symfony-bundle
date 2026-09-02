# fluffydiscord/rapira-symfony-bundle

Runs a Symfony application as a resident worker under [Rapira](https://github.com/rapira-rs/rapira)
in classic or **dispatcher mode**.

Only the HTTP worker is provided. Jobs / KV / Centrifugo / Temporal are not (waiting for Rapira).

## Requirements

- PHP >= 8.4
- Rapira running the pool in `mode = "dispatcher"` (classic mode falls back to the standard runtime)
- `symfony/*` `^7.4 || ^8`

## Installation

```bash
composer require fluffydiscord/rapira-symfony-bundle
```

Register the bundle (`config/bundles.php`):

```php
FluffyDiscord\RapiraBundle\FluffyDiscordRapiraBundle::class => ['all' => true],
```

Use the kernel trait (`src/Kernel.php`) so a rebooted kernel keeps the properties the resident
worker preserves:

```php
use FluffyDiscord\RapiraBundle\Kernel\RapiraMicroKernelTrait;

class Kernel extends BaseKernel
{
    use RapiraMicroKernelTrait;
}
```

The dispatcher entrypoint, **`worker.php`, ships with the bundle** — it bootstraps `.env` itself
with `usePutenv()` (no `symfony/runtime`) and hands the booted kernel to the worker. There is
nothing to write: point `rapira.toml` at the vendored file. `public/index.php` and `bin/console`
stay exactly as the skeleton ships them and are used for classic mode and the console.

```toml
[http]
listen = "0.0.0.0:8000"

[pool]
entrypoint = "vendor/fluffydiscord/rapira-symfony-bundle/worker.php"
mode = "dispatcher"
processes = 4

[log]
level = "info"
format = "json"
```

The shipped worker defaults the kernel class to `App\Kernel`; override it with the
`APP_KERNEL_CLASS` environment variable if yours differs. Classic mode and the console keep using
the standard runtime (`rapira serve --classic public/index.php`, `php bin/console`) — no
`SCRIPT_FILENAME` override is needed there because the classic SAPI sets it.

## Configuration

```yaml
# config/packages/rapira.yaml
rapira:
    http:
        lazy_boot: false
    warmup:
        enabled: true            # boot-time warmers + learned-manifest recorder
        learn: true
        learn_requests: 30       # stop recording after N responses per worker
        manifest_path: ~         # default: %kernel.cache_dir%/rapira/warmup.manifest.json
    doctrine:
        preconnect: true         # open Postgres connections at worker boot
    profiling:
        xhprof:
            enabled: false       # off by default; "auto" = ext-xhprof loaded and kernel.debug; true forces
            output_dir: ~        # default: ini xhprof.output_dir, then sys_get_temp_dir()/xhprof
    vips:                        # bound libvips's process-global cache at worker boot
        enabled: auto            # auto = jcupitt/vips installed; true/false force
        max_operations: 50
        max_memory_mb: 50
        max_files: 20
```

## Dispatcher-mode notes

- **Streaming.** `StreamedResponse` (and `StreamedJsonResponse`) stream progressively and carry
  `X-Accel-Buffering: no`. Callbacks must `echo` (or use `setChunks()`) — a `yield`-based callback
  is **not** executed by Symfony's `sendContent()`.
- **Superglobals are empty.** Read request data from the injected `Request`, never from
  `$_GET`/`$_SERVER`; `echo`/`header()` output is discarded — respond through the returned Response.
- **Logging.** `error_log()` output is discarded by Rapira in dispatcher mode; the bundle logs
  through `\Rapira\log()` (the `app` log target).
- **Sessions** work unchanged (native or `PdoSessionHandler`); the `services_resetter` clears
  per-request state between requests.
- **Uploads** are parsed host-side (`[http.uploads]`); the request factory maps them to Symfony
  `UploadedFile`s. `move()` renames the host-spooled temp file before the exchange finalizes.

## Events

`WorkerBootingEvent`, `WorkerRequestReceivedEvent` (carries the `Rapira\Http\Request`),
`WorkerResponseSentEvent` (Symfony request + response), `WorkerRequestFailedEvent`
(`Rapira\Http\Request` + throwable).

## Testing

```bash
tests/docker-qa.sh                # PHPStan (level max) + PHPUnit, in a container
tests/docker/run-integration.sh   # IT-101..IT-107 against the real Rapira binary
```
