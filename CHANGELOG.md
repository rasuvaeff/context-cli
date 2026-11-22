# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#7), and `rasuvaeff/context` may now be `^0.3 || ^0.5` —
  the 0.3 line stays for prefer-lowest, the 0.5 line carries 8.6. CI runs
  PHP 8.6 as a regular matrix entry; the backward-compatibility job runs
  on PHP 8.5.

## 0.1.2 — 2026-11-21

- Fixed: `SignalScope::listen()` with a signal number the kernel does not
  know (`pcntl_signal_get_handler()` throws `ValueError`, e.g. 99 on Linux)
  left a never-cancelled child context attached to the parent. The handlers
  are now read before the scoped context is derived, so the failure leaves
  nothing behind (#4).

## 0.1.1 — 2026-11-20

- Fixed: the second signal only restores the previous handlers; it no longer
  applies the deferred cancellation inside the signal handler, so no
  `onTerminate()` listener runs there and a throwing listener can no longer
  skip the forced exit. `restore()`/`run()` still cancel the context in the
  normal flow. README Security now says the previous PHP handler is called
  from inside the signal handler on the second signal (#3).
- Mutation testing now counts code that tests execute without naming it in
  `#[Covers]` (`infection --with-uncovered`, template `PublicVisibility`/
  `ProtectedVisibility` switches and default-parameter signature ignore). New
  tests pin the re-raise of a second signal under the default disposition (an
  in-process run plus a forked child that must die of the signal) and the
  fallback for a previous handler that is not callable from the scope.
  Behaviour-neutral `src/` change: `SignalScope::listen()`/`run()` signatures are
  split one parameter per line and the default signal set moved to a private
  constant `DEFAULT_SIGNALS` (still `[SIGTERM, SIGINT]`).

## 0.1.0 — 2026-10-15

- Initial release: `SignalScope` (`listen()`, `run()`, `context()`,
  `signal()`, `restore()`) cancels a `rasuvaeff/context` context on
  SIGTERM/SIGINT through `requestCancel()`; a second signal restores and
  hands itself to the previous disposition. `SignalReceived` is the reason.
  `restore()` cancels the scoped context (like Go's `NotifyContext` stop), so
  a scope per job never stays attached to a long-lived parent.
