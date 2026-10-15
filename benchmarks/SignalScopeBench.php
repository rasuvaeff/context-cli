<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Cli\Benchmarks;

use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Testo\Bench;

/**
 * Cost of a scope per job: install and restore the handlers of two signals,
 * against the bare withCancel() every scope performs.
 */
final class SignalScopeBench
{
    private static ?Context $parent = null;

    #[Bench(
        callables: [
            'withCancel() only' => [self::class, 'deriveOnly'],
        ],
        calls: 10_000,
        iterations: 10,
    )]
    public static function listenAndRestore(): ?int
    {
        $scope = SignalScope::listen(self::$parent ??= Context::background(), [\SIGUSR1, \SIGUSR2]);
        $scope->restore();

        return $scope->signal();
    }

    public static function deriveOnly(): bool
    {
        [$context] = (self::$parent ??= Context::background())->withCancel();

        return $context->isTerminated();
    }
}
