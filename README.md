# rasuvaeff/context-cli

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-cli/v)](https://packagist.org/packages/rasuvaeff/context-cli)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-cli/downloads)](https://packagist.org/packages/rasuvaeff/context-cli)
[![Build](https://github.com/rasuvaeff/context-cli/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-cli/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-cli/php)](https://packagist.org/packages/rasuvaeff/context-cli)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Graceful shutdown for PHP CLI workers: `SIGTERM`/`SIGINT` cancel a
[`rasuvaeff/context`](https://github.com/rasuvaeff/context) `Context`, so a
queue worker or a long-running command finishes its current step and exits
cleanly — on deploy, on Kubernetes pod termination, on Ctrl+C.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+
- `ext-pcntl`, `ext-posix` (Linux/macOS; not available on Windows)
- `rasuvaeff/context` ^0.3

## Installation

```bash
composer require rasuvaeff/context-cli
```

## Limitations

- **The first signal stops the work at the next check, not mid-call.** The
  handler only requests cancellation (`ContextController::requestCancel()`);
  your code sees it at the next `assertActive()`, `sleep()`, `iterate()` or
  `is*()` call. Blocking calls that a signal interrupts — `usleep()`,
  `sleep()`, `stream_select()`, `Context::sleep()` — return early; curl with
  `CURLOPT_NOSIGNAL` and most database drivers finish first, bounded by
  their own timeouts.
- **One process, one handler table.** Two active scopes cannot own the same
  signal (`LogicException`); while a scope is active, the previous handler of
  its signals does not run.
- **Windows is not supported** — `ext-pcntl` does not exist there.

## Usage

### Run a worker under a scope

```php
use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;

try {
    SignalScope::run(Context::background(), static function (Context $context) use ($queue): void {
        while (true) {
            $context->assertActive();               // stop between messages
            $message = $queue->reserve(timeout: 5);
            if ($message === null) {
                continue;
            }

            [$job, $jobController] = $context->withTimeout($message->ttr());
            try {
                handle($message, $job);
                $queue->ack($message);
            } finally {
                $jobController->cancel();
            }
        }
    });
} catch (ContextCancelledException $stopped) {
    $logger->info('worker stopped', ['reason' => $stopped->getPrevious()?->getMessage()]); // "Received SIGTERM"
}
```

`run()` installs the handlers, passes the scoped context to the callback and
restores the previous handlers afterwards — even when the callback throws.

### Manual scope

```php
$scope = SignalScope::listen(Context::background(), signals: [\SIGTERM, \SIGINT, \SIGHUP]);
try {
    work($scope->context());
} finally {
    $scope->restore();
}

$scope->signal();   // the first signal received, or null
```

### Behaviour

| Event | What happens |
|---|---|
| First signal of the set | `requestCancel(new SignalReceived($signal))`. Nothing else runs in the handler; the next check terminates the context, runs its `onTerminate()` listeners and throws `ContextCancelledException` with the `SignalReceived` as `previous` |
| Second signal of the set | The scope restores the previous handlers and hands this signal to them: a PHP handler is called, the default disposition terminates the process (Ctrl+C twice stops a stuck worker), an ignored signal stays ignored |
| `restore()` | Previous handlers and the previous `pcntl_async_signals()` mode come back, then the scoped context is cancelled (like Go's `NotifyContext` stop) so it never stays attached to a long-lived parent; a signal cause already received is kept; idempotent |
| Parent cancelled/expired | The scoped context terminates with it (it is `$parent->withCancel()`) |
| `SIGKILL`, `SIGSTOP`, duplicates, empty set | `InvalidArgumentException` before anything is installed |
| A signal already owned by another active scope | `LogicException` |

The scope enables `pcntl_async_signals(true)` while it is active, so signals
are delivered between PHP opcodes without manual `pcntl_signal_dispatch()`.

### Kubernetes / systemd

Both send `SIGTERM`, wait for the grace period (`terminationGracePeriodSeconds`
/ `TimeoutStopSec`), then `SIGKILL`. The worker stops at the next check, so the
longest step between two checks — one message's TTR — must fit in that period.

### Public API

| Class | Description |
|---|---|
| `SignalScope` | `listen(Context $parent, list<int> $signals = [SIGTERM, SIGINT])`, `run(Context $parent, callable(Context): T $work, list<int> $signals)`, `context()`, `signal()`, `restore()` |
| `SignalReceived` | Cancellation reason (`RuntimeException`): `signal(): int`, `name(int): string` (`"SIGTERM"`, `"signal 14"`) |

## Security

The package installs process-wide signal handlers and restores them; it never
runs application code inside a handler. A second signal deliberately falls
back to the previous disposition, so an operator can always stop the process.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `worker.php` | Graceful stop on SIGTERM/SIGINT; `--stuck` shows the second Ctrl+C terminating a worker that never checks | ext-pcntl, no server |

## Development

No PHP/Composer on the host — run in Docker. `composer:2` has no `ext-pcntl`,
so build a local image once:

```bash
make image                 # rasuvaeff/composer-pcntl:local = composer:2 + pcntl
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## License

[BSD-3-Clause](LICENSE.md)
