---
name: rasuvaeff-context-cli
description: >-
  Graceful shutdown for PHP CLI workers with rasuvaeff/context-cli —
  SignalScope::run()/listen() turn SIGTERM/SIGINT into cancellation of a
  rasuvaeff/context Context, SignalReceived is the reason, a second signal
  falls back to the previous disposition. Use when writing or reviewing a
  queue worker, daemon or long-running console command that must stop cleanly
  on deploy, Kubernetes pod termination or Ctrl+C.
---

# rasuvaeff/context-cli

OS signals → `Context` cancellation for CLI processes. Namespace
`Rasuvaeff\Context\Cli\`. Needs `ext-pcntl` + `ext-posix` (no Windows).

## Safety rules — verify these on every change

1. **Never install signal handlers by hand next to a scope.** One process has
   one handler table; a second scope on the same signal throws
   `LogicException`. Use `SignalScope::run()` (restores in `finally`) or
   `listen()` + `restore()` in `finally`.

2. **Nothing runs in the handler.** The first signal only calls
   `requestCancel()`; work stops at the next `assertActive()` / `sleep()` /
   `iterate()` / `is*()`. Put a check between every message/step — a loop
   without checks never stops gracefully (the second signal kills it).

3. **Never call `ContextController::cancel()` from your own signal handler** —
   it runs listeners at an arbitrary point of the interrupted code. Use
   `requestCancel()` (that is what this package does).

4. **The step between two checks must fit in the grace period**
   (`terminationGracePeriodSeconds`, systemd `TimeoutStopSec`): after it the
   process gets SIGKILL and a half-done job is lost. Derive each job from the
   scoped context with its TTR: `[$job, $c] = $ctx->withTimeout($ttr)`.

5. **Tests: signal the test process with SIGUSR1/SIGUSR2,** never
   SIGTERM/SIGINT, and dispatch explicitly (`posix_kill(posix_getpid(), $s);
   pcntl_signal_dispatch();`). Real disposition behaviour (termination on the
   second signal) needs a child process.

## Canonical usage

```php
use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;

try {
    SignalScope::run(Context::background(), static function (Context $ctx) use ($queue): void {
        while (true) {
            $ctx->assertActive();
            $message = $queue->reserve(timeout: 5);
            if ($message === null) {
                continue;
            }
            [$job, $c] = $ctx->withTimeout($message->ttr());
            try { handle($message, $job); $queue->ack($message); } finally { $c->cancel(); }
        }
    });
} catch (ContextCancelledException $stopped) {
    // $stopped->getPrevious() is SignalReceived: "Received SIGTERM"
}
```

## Full API

Read `vendor/rasuvaeff/context-cli/llms.txt`; for the context itself
(`sleep()`, `iterate()`, `withTimeout()`), `vendor/rasuvaeff/context/llms.txt`.
