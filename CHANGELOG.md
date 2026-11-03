# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
