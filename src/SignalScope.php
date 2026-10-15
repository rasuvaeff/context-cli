<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Cli;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;

/**
 * Cancels a context when the process receives one of the given signals —
 * graceful shutdown for workers and long-running commands.
 *
 * The first signal only *requests* cancellation (`requestCancel()`): nothing
 * runs inside the signal handler, and the next check of the context applies
 * it in the normal flow. A second signal from the set restores the previous
 * handlers and re-raises itself, so a worker stuck in a blocking call can
 * still be stopped by pressing Ctrl+C twice.
 *
 * @api
 */
final class SignalScope
{
    /**
     * Signals currently owned by an active scope; one process, one handler table.
     *
     * @var array<int, self>
     */
    private static array $owned = [];

    private ?int $received = null;

    private bool $restored = false;

    /**
     * @param non-empty-list<int> $signals
     * @param array<int, (\Closure(int, mixed): void)|int> $previousHandlers
     */
    private function __construct(
        private readonly Context $context,
        private readonly ContextController $controller,
        private readonly array $signals,
        private readonly array $previousHandlers,
        private readonly bool $previousAsync,
    ) {}

    /**
     * Installs the handlers and returns the scope; call {@see restore()} in
     * `finally`, or use {@see run()}.
     *
     * @param list<int> $signals
     *
     * @throws \InvalidArgumentException on an empty, duplicate or uncatchable signal
     * @throws \LogicException when another active scope owns one of the signals
     */
    public static function listen(Context $parent, array $signals = [\SIGTERM, \SIGINT]): self
    {
        $signals = self::validated($signals);

        foreach ($signals as $signal) {
            if (isset(self::$owned[$signal])) {
                throw new \LogicException(sprintf('%s is already handled by another signal scope', SignalReceived::name($signal)));
            }
        }

        [$context, $controller] = $parent->withCancel();
        $previousHandlers = [];
        foreach ($signals as $signal) {
            $previousHandlers[$signal] = self::currentHandler($signal);
        }

        $scope = new self(
            context: $context,
            controller: $controller,
            signals: $signals,
            previousHandlers: $previousHandlers,
            previousAsync: pcntl_async_signals(),
        );

        foreach ($signals as $signal) {
            self::$owned[$signal] = $scope;
            pcntl_signal($signal, $scope->handle(...));
        }
        pcntl_async_signals(enable: true);

        return $scope;
    }

    /**
     * Runs `$work` under a scope and restores the handlers afterwards, even
     * when `$work` throws.
     *
     * @template T
     *
     * @param callable(Context): T $work
     * @param list<int> $signals
     *
     * @return T
     */
    public static function run(Context $parent, callable $work, array $signals = [\SIGTERM, \SIGINT]): mixed
    {
        $scope = self::listen($parent, $signals);

        try {
            return $work($scope->context());
        } finally {
            $scope->restore();
        }
    }

    public function context(): Context
    {
        return $this->context;
    }

    /** The first signal received, or null. */
    public function signal(): ?int
    {
        return $this->received;
    }

    /**
     * Puts back the previous handlers and the previous async-signals mode,
     * then cancels the scoped context (like Go's `NotifyContext` stop): the
     * context belongs to the scope, and an uncancelled child would stay
     * attached to a long-lived parent forever. A context already cancelled
     * by a signal keeps that cause. Idempotent.
     */
    public function restore(): void
    {
        if ($this->restored) {
            return;
        }

        $this->restored = true;
        foreach ($this->signals as $signal) {
            pcntl_signal($signal, $this->previousHandlers[$signal] ?? \SIG_DFL);
            unset(self::$owned[$signal]);
        }
        pcntl_async_signals($this->previousAsync);
        $this->context->isTerminated();
        $this->controller->cancel();
    }

    private function handle(int $signal): void
    {
        if ($this->received === null) {
            $this->received = $signal;
            $this->controller->requestCancel(new SignalReceived($signal));

            return;
        }

        // Second signal: give up on graceful shutdown and hand this very
        // signal to the previous disposition. A PHP handler is called
        // directly (re-raising would leave it queued while async signals are
        // off); the default disposition is re-raised so the kernel applies it
        // (terminate); an ignored signal stays ignored.
        $previous = $this->previousHandlers[$signal] ?? \SIG_DFL;
        $this->restore();

        if ($previous instanceof \Closure) {
            $previous($signal, null);
        } elseif ($previous === \SIG_DFL) {
            posix_kill(posix_getpid(), $signal);
        }
    }

    /** @return (\Closure(int, mixed): void)|int */
    private static function currentHandler(int $signal): \Closure|int
    {
        $handler = pcntl_signal_get_handler($signal);
        if (\is_int($handler)) {
            return $handler;
        }

        if (!\is_callable($handler)) {
            return \SIG_DFL;
        }

        $callable = \Closure::fromCallable($handler);

        return static function (int $signo, mixed $info = null) use ($callable): void {
            $callable($signo, $info);
        };
    }

    /**
     * @param list<int> $signals
     *
     * @return non-empty-list<int>
     */
    private static function validated(array $signals): array
    {
        if ($signals === []) {
            throw new \InvalidArgumentException('At least one signal is required');
        }

        if (\count(array_unique($signals)) !== \count($signals)) {
            throw new \InvalidArgumentException('Signals must be unique');
        }

        foreach ($signals as $signal) {
            if ($signal === \SIGKILL || $signal === \SIGSTOP || $signal < 1) {
                throw new \InvalidArgumentException(sprintf('%s cannot be handled', SignalReceived::name($signal)));
            }
        }

        return $signals;
    }
}
