# AGENTS.md — context-cli

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/context-cli` turns OS signals into cancellation of a
`rasuvaeff/context` `Context` for CLI workers. Public API:
`Rasuvaeff\Context\Cli\SignalScope` (`listen()`, `run()`, `context()`,
`signal()`, `restore()`) and the reason `Rasuvaeff\Context\Cli\SignalReceived`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Nothing runs inside a signal handler but `requestCancel()`.** Never call
   `ContextController::cancel()`, a listener, a logger or any application
   callback from `SignalScope::handle()` — it executes at an arbitrary point of
   the interrupted code. The only other thing the handler may do is the
   second-signal fallback (restore + hand the signal to the previous
   disposition).
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host, and `composer:2` has no `ext-pcntl`: build the
local image once, then use Make (every target runs in that image).

```bash
make image          # rasuvaeff/composer-pcntl:local = composer:2 + pcntl
make install
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

Raw Docker: replace `composer:2` with `rasuvaeff/composer-pcntl:local`, e.g.
`docker run --rm -v "$PWD":/app -w /app rasuvaeff/composer-pcntl:local composer build`.
`bin/build-digest` hardcodes `composer:2` and cannot run this package.

## Invariants & gotchas

- **One process, one handler table.** `SignalScope::$owned` is process-wide;
  `restore()` must release every signal it owned and put back the previous
  handler and the previous `pcntl_async_signals()` mode. Keep it idempotent.
- **Previous handlers are normalised** to `int` (`SIG_DFL`/`SIG_IGN`) or a
  `Closure(int, mixed): void` wrapper — `pcntl_signal_get_handler()` returns
  `callable|int` and psalm needs a concrete closure to hand back to
  `pcntl_signal()`.
- **Second signal:** a PHP previous handler is *called directly*; only
  `SIG_DFL` is re-raised with `posix_kill()` (the kernel applies it). Re-raising
  to a PHP handler left the signal queued while async signals were off — it
  leaked into the next test.
- **Validate before installing anything**: an invalid set must not leave a
  handler or the async mode behind.
- Tests signal the runner itself with `SIGUSR1`/`SIGUSR2` and call
  `pcntl_signal_dispatch()` explicitly; `#[BeforeTest]` installs recording
  handlers, `#[AfterTest]` resets to `SIG_DFL`. Never send SIGTERM/SIGINT to
  the runner. `WorkerProcessTest` runs `examples/worker.php` as a child process
  for the real dispositions — keep that script's output lines and exit codes
  stable.
- `examples/worker.php` must end by itself (2 s budget) when run without
  arguments: `bin/package-audit` executes examples.
- Code: `declare(strict_types=1)`, `final` classes, `#[\Override]`, explicit
  types, `\z` in validation regexes.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no findings; never put a
  `${{ }}` expression straight into `run:` (pass it through `env:`).

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `make build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
