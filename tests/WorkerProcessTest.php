<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Cli\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Runs examples/worker.php as a real process and signals it from outside:
 * the default dispositions (terminate on the second signal) only show up
 * across a process boundary.
 */
#[Test]
#[CoversNothing]
final class WorkerProcessTest
{
    public function sigtermStopsTheWorkerGracefully(): void
    {
        [$process, $pipes] = $this->start([]);

        proc_terminate($process, \SIGTERM);
        $output = stream_get_contents($pipes[1]);
        $status = $this->wait($process);

        Assert::string((string) $output)->contains('stopped gracefully: Received SIGTERM');
        Assert::false($status['signaled']);
        Assert::same($status['exitcode'], 0);
    }

    public function withoutASignalTheBudgetEndsTheWorker(): void
    {
        [$process, $pipes] = $this->start([]);

        $output = stream_get_contents($pipes[1]);
        $status = $this->wait($process);

        Assert::string((string) $output)->contains('no signal within the budget');
        Assert::same($status['exitcode'], 0);
    }

    public function aSecondSigintTerminatesAStuckWorker(): void
    {
        [$process, $pipes] = $this->start(['--stuck']);

        proc_terminate($process, \SIGINT);
        usleep(300_000);
        Assert::true(proc_get_status($process)['running']);

        proc_terminate($process, \SIGINT);
        $status = $this->wait($process);
        fclose($pipes[1]);

        Assert::true($status['signaled']);
        Assert::same($status['termsig'], \SIGINT);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{resource, array<int, resource>}
     */
    private function start(array $arguments): array
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/../examples/worker.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start the worker');
        }

        $ready = fgets($pipes[1]);
        if (!\is_string($ready) || !str_starts_with($ready, 'ready')) {
            throw new \RuntimeException('The worker did not start: ' . var_export($ready, return: true) . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    /**
     * @param resource $process
     *
     * @return array{signaled: bool, termsig: int, exitcode: int}
     */
    private function wait($process): array
    {
        $deadline = hrtime(as_number: true) + 15_000_000_000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return ['signaled' => $status['signaled'], 'termsig' => $status['termsig'], 'exitcode' => $status['exitcode']];
            }
            usleep(20_000);
        } while (hrtime(as_number: true) < $deadline);

        proc_terminate($process, \SIGKILL);

        throw new \RuntimeException('The worker did not exit');
    }
}
