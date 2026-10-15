<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Cli;

/**
 * The cancellation reason a {@see SignalScope} attaches to its context: the
 * `previous` of the `ContextCancelledException` thrown by `assertActive()`.
 *
 * @api
 */
final class SignalReceived extends \RuntimeException
{
    private const array NAMES = [
        \SIGHUP => 'SIGHUP',
        \SIGINT => 'SIGINT',
        \SIGQUIT => 'SIGQUIT',
        \SIGTERM => 'SIGTERM',
        \SIGUSR1 => 'SIGUSR1',
        \SIGUSR2 => 'SIGUSR2',
    ];

    public function __construct(private readonly int $signal)
    {
        parent::__construct(sprintf('Received %s', self::name($signal)));
    }

    public function signal(): int
    {
        return $this->signal;
    }

    public static function name(int $signal): string
    {
        return self::NAMES[$signal] ?? sprintf('signal %d', $signal);
    }
}
