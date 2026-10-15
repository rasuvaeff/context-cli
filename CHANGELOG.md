# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-10-15

- Initial release: `SignalScope` (`listen()`, `run()`, `context()`,
  `signal()`, `restore()`) cancels a `rasuvaeff/context` context on
  SIGTERM/SIGINT through `requestCancel()`; a second signal restores and
  hands itself to the previous disposition. `SignalReceived` is the reason.
  `restore()` cancels the scoped context (like Go's `NotifyContext` stop), so
  a scope per job never stays attached to a long-lived parent.
