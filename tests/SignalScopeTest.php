<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Cli\Tests;

use Rasuvaeff\Context\Cli\SignalReceived;
use Rasuvaeff\Context\Cli\SignalScope;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Signals are sent to the test process itself; SIGUSR1/SIGUSR2 stand in for
 * SIGTERM/SIGINT so a failing test never terminates the runner. Every test
 * starts with a recording handler as the "previous" disposition.
 */
#[Test]
#[Covers(SignalScope::class)]
#[Covers(SignalReceived::class)]
final class SignalScopeTest
{
    private const array SIGNALS = [\SIGUSR1, \SIGUSR2];

    /** @var list<int> */
    private array $previous = [];

    #[BeforeTest]
    public function installRecordingHandlers(): void
    {
        $this->previous = [];
        pcntl_async_signals(enable: false);
        foreach (self::SIGNALS as $signal) {
            pcntl_signal($signal, function (int $signo): void {
                $this->previous[] = $signo;
            });
        }
    }

    #[AfterTest]
    public function resetHandlers(): void
    {
        foreach (self::SIGNALS as $signal) {
            pcntl_signal($signal, \SIG_DFL);
        }
        pcntl_async_signals(enable: false);
    }

    public function theFirstSignalCancelsTheContextAtTheNextCheck(): void
    {
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);
        $context = $scope->context();
        $ranInsideHandler = null;
        $inHandler = false;
        $context->onTerminate(static function () use (&$ranInsideHandler, &$inHandler): void {
            $ranInsideHandler = $inHandler;
        });

        $this->send(\SIGUSR1);

        Assert::same($scope->signal(), \SIGUSR1);
        Assert::null($ranInsideHandler);

        try {
            $context->assertActive();
        } catch (ContextCancelledException $exception) {
            $reason = $exception->getPrevious();
            Assert::instanceOf($reason, SignalReceived::class);
            Assert::same($reason instanceof SignalReceived ? $reason->signal() : null, \SIGUSR1);
            Assert::same($reason?->getMessage(), 'Received SIGUSR1');
            Assert::false($ranInsideHandler);
            Assert::same($this->previous, []);
            $scope->restore();

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aSecondSignalRestoresAndReRaisesItself(): void
    {
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);

        $this->send(\SIGUSR1);
        $this->send(\SIGUSR2);

        Assert::same($this->previous, [\SIGUSR2]);
        Assert::same($scope->signal(), \SIGUSR1);

        $this->send(\SIGUSR1);

        Assert::same($this->previous, [\SIGUSR2, \SIGUSR1]);
    }

    public function restoringPutsBackHandlersAndTheAsyncMode(): void
    {
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);

        Assert::true(pcntl_async_signals());

        $scope->restore();
        $scope->restore();

        Assert::false(pcntl_async_signals());
        $this->send(\SIGUSR1);
        Assert::same($this->previous, [\SIGUSR1]);
        Assert::false($scope->context()->isTerminated());
        Assert::null($scope->signal());
    }

    public function aLateRestoreDoesNotStealTheSignalFromTheNextScope(): void
    {
        $first = SignalScope::listen(Context::background(), self::SIGNALS);
        $first->restore();
        $second = SignalScope::listen(Context::background(), self::SIGNALS);

        $first->restore();
        $this->send(\SIGUSR1);

        Assert::true($second->context()->isCancelled());
        Assert::same($this->previous, []);
        $second->restore();
    }

    public function anIgnoredSignalStaysIgnored(): void
    {
        pcntl_signal(\SIGUSR1, \SIG_IGN);
        $scope = SignalScope::listen(Context::background(), [\SIGUSR1]);

        $this->send(\SIGUSR1);
        $this->send(\SIGUSR1);

        Assert::same(pcntl_signal_get_handler(\SIGUSR1), \SIG_IGN);
        Assert::same($this->previous, []);
        Assert::true($scope->context()->isCancelled());
    }

    public function sighupCanBeHandled(): void
    {
        $scope = SignalScope::listen(Context::background(), [\SIGHUP]);

        Assert::true(\is_callable(pcntl_signal_get_handler(\SIGHUP)));

        $scope->restore();

        Assert::same(pcntl_signal_get_handler(\SIGHUP), \SIG_DFL);
    }

    public function restoringKeepsACancelledContextCancelled(): void
    {
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);
        $this->send(\SIGUSR2);

        $scope->restore();

        Assert::true($scope->context()->isCancelled());
    }

    public function aSignalOutsideTheSetIsNotHandled(): void
    {
        $scope = SignalScope::listen(Context::background(), [\SIGUSR1]);

        $this->send(\SIGUSR2);

        Assert::same($this->previous, [\SIGUSR2]);
        Assert::false($scope->context()->isTerminated());
        $scope->restore();
    }

    public function theParentCancelsTheScopedContext(): void
    {
        [$parent, $controller] = Context::background()->withCancel();
        $scope = SignalScope::listen($parent, self::SIGNALS);

        $controller->cancel();

        Assert::true($scope->context()->isCancelled());
        $scope->restore();
    }

    public function aSignalCanBeOwnedByOneScopeAtATime(): void
    {
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);

        try {
            SignalScope::listen(Context::background(), [\SIGUSR2]);
        } catch (\LogicException $exception) {
            Assert::same($exception->getMessage(), 'SIGUSR2 is already handled by another signal scope');
            $scope->restore();
            SignalScope::listen(Context::background(), [\SIGUSR2])->restore();

            return;
        }

        throw new \LogicException('a second scope on the same signal was expected to fail');
    }

    /**
     * @param list<int> $signals
     */
    #[DataProvider('invalidSignalsProvider')]
    public function invalidSignalSetsAreRejected(array $signals, string $message): void
    {
        try {
            SignalScope::listen(Context::background(), $signals);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), $message);
            Assert::false(pcntl_async_signals());

            return;
        }

        throw new \LogicException('an invalid signal set was expected to fail');
    }

    public static function invalidSignalsProvider(): iterable
    {
        yield 'empty' => [[], 'At least one signal is required'];
        yield 'duplicate' => [[\SIGUSR1, \SIGUSR1], 'Signals must be unique'];
        yield 'SIGKILL' => [[\SIGKILL], 'signal 9 cannot be handled'];
        yield 'SIGSTOP' => [[\SIGSTOP], sprintf('signal %d cannot be handled', \SIGSTOP)];
        yield 'zero' => [[0], 'signal 0 cannot be handled'];
    }

    public function runRestoresAfterTheWork(): void
    {
        $result = SignalScope::run(
            Context::background(),
            function (Context $context): string {
                $this->send(\SIGUSR1);

                return $context->isCancelled() ? 'stopped' : 'finished';
            },
            self::SIGNALS,
        );

        Assert::same($result, 'stopped');
        Assert::false(pcntl_async_signals());
        $this->send(\SIGUSR1);
        Assert::same($this->previous, [\SIGUSR1]);
    }

    public function runRestoresWhenTheWorkThrows(): void
    {
        try {
            SignalScope::run(Context::background(), static function (): never {
                throw new \DomainException('work failed');
            }, self::SIGNALS);
        } catch (\DomainException) {
            $this->send(\SIGUSR2);
            Assert::same($this->previous, [\SIGUSR2]);

            return;
        }

        throw new \LogicException('the work exception was expected');
    }

    public function theDefaultSetIsTermAndInt(): void
    {
        $scope = SignalScope::listen(Context::background());

        Assert::true(\is_callable(pcntl_signal_get_handler(\SIGTERM)));
        Assert::true(\is_callable(pcntl_signal_get_handler(\SIGINT)));

        $scope->restore();

        Assert::same(pcntl_signal_get_handler(\SIGTERM), \SIG_DFL);
        Assert::same(pcntl_signal_get_handler(\SIGINT), \SIG_DFL);
    }

    #[DataProvider('signalNameProvider')]
    public function signalsAreNamed(int $signal, string $name): void
    {
        Assert::same(SignalReceived::name($signal), $name);
        Assert::same((new SignalReceived($signal))->signal(), $signal);
    }

    public static function signalNameProvider(): iterable
    {
        yield 'SIGHUP' => [\SIGHUP, 'SIGHUP'];
        yield 'SIGINT' => [\SIGINT, 'SIGINT'];
        yield 'SIGQUIT' => [\SIGQUIT, 'SIGQUIT'];
        yield 'SIGTERM' => [\SIGTERM, 'SIGTERM'];
        yield 'SIGUSR1' => [\SIGUSR1, 'SIGUSR1'];
        yield 'SIGUSR2' => [\SIGUSR2, 'SIGUSR2'];
        yield 'unnamed' => [\SIGALRM, sprintf('signal %d', \SIGALRM)];
    }

    /**
     * @param non-empty-list<int> $sequence
     */
    #[Property(runs: 100)]
    public function onlyTheFirstSignalCancelsAndTheRestGoToThePreviousHandler(array $sequence): void
    {
        $this->installRecordingHandlers();
        $scope = SignalScope::listen(Context::background(), self::SIGNALS);

        foreach ($sequence as $signal) {
            $this->send($signal);
        }

        Assert::same($scope->signal(), $sequence[0]);
        Assert::true($scope->context()->isCancelled());
        Assert::same($this->previous, \array_slice($sequence, 1));

        $scope->restore();
        $this->resetHandlers();

        Classify::cover(\count($sequence) === 1, 'a single signal', 5);
        Classify::cover(\count($sequence) > 2, 'signals after the restore', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function onlyTheFirstSignalCancelsAndTheRestGoToThePreviousHandlerGenerators(): array
    {
        return [
            'sequence' => Gen::nonEmptyArrayOf(Gen::elements(self::SIGNALS), 6),
        ];
    }

    /** @return iterable<string, array{non-empty-list<int>}> */
    public static function onlyTheFirstSignalCancelsAndTheRestGoToThePreviousHandlerExamples(): iterable
    {
        yield 'one signal' => [[\SIGUSR1]];
        yield 'Ctrl+C twice' => [[\SIGUSR2, \SIGUSR2]];
        yield 'mixed after the restore' => [[\SIGUSR1, \SIGUSR2, \SIGUSR1]];
    }

    public function anInvalidSetLeavesNothingInstalled(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        try {
            SignalScope::listen(Context::background(), [\SIGUSR1, \SIGKILL]);
        } finally {
            $this->send(\SIGUSR1);
            Assert::same($this->previous, [\SIGUSR1]);
        }
    }

    /** Sends the signal to this process and dispatches it before returning. */
    private function send(int $signal): void
    {
        posix_kill(posix_getpid(), $signal);
        pcntl_signal_dispatch();
    }
}
