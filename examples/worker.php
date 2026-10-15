<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;

// A worker that stops gracefully on SIGTERM/SIGINT. Run it, then press Ctrl+C
// (or `kill -TERM <pid>`). With --stuck it never checks the context, so the
// first Ctrl+C is ignored and the second one terminates it.
$stuck = in_array('--stuck', $argv, true);
[$budget] = Context::background()->withTimeout($stuck ? 30.0 : 2.0);

try {
    $jobs = SignalScope::run($budget, static function (Context $context) use ($stuck): int {
        echo 'ready ' . getmypid() . PHP_EOL;
        $jobs = 0;

        if ($stuck) {
            $until = hrtime(true) + 10_000_000_000;
            while (hrtime(true) < $until) {
                usleep(100_000);                    // a blocking call that never checks
            }

            return $jobs;
        }

        while (true) {
            $context->sleep(0.05);                  // one "job"; wakes early on a signal
            $jobs++;
        }
    });
    echo "finished {$jobs} jobs" . PHP_EOL;
} catch (ContextCancelledException $cancelled) {
    echo 'stopped gracefully: ' . $cancelled->getPrevious()?->getMessage() . PHP_EOL;
} catch (ContextExpiredException) {
    echo 'no signal within the budget' . PHP_EOL;
}
