<?php

declare(strict_types=1);

namespace TiMacDonald\Log;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @no-named-arguments
 */
class ChannelFake implements LoggerInterface
{
    use LogHelpers;

    private Dispatcher $dispatcher;

    /**
     * @var array<int, array{ level: mixed, message: string, context: array<string, mixed>, channel: string, times_channel_has_been_forgotten_at_time_of_writing_log: int }>
     */
    private array $logs = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $context = [];

    private int $timesForgotten = 0;

    private bool $isCurrentlyForgotten = false;

    /**
     * @var array{ '_': stdClass }
     */
    private array $sentinalContext;

    /**
     * @internal
     */
    public function __construct(private string $name)
    {
        $this->sentinalContext = ['_' => new stdClass()];
    }

    /**
     * @api
     */
    public function dump(?string $level = null): ChannelFake
    {
        $callback = $level === null
            ? fn (): bool => true
            : fn (array $log) => $log['level'] === $level;

        $this->logs()
            ->filter($callback)
            ->values()
            ->dump();

        return $this;
    }

    /**
     * @api
     * @codeCoverageIgnore
     * @infection-ignore-all
     */
    public function dd(?string $level = null): never
    {
        $this->dump($level);

        exit(1);
    }

    /**
     * @api
     * @link https://github.com/timacdonald/log-fake#assertlogged Documentation
     */
    public function assertLogged(string $level, ?Closure $callback = null): ChannelFake
    {
        // TODO: document what the closure accepts. Does PHPStan have a rule for this?
        PHPUnit::assertTrue(
            $this->logged($level, $callback)->count() > 0,
            "An expected log with level [{$level}] was not logged in the [{$this->name}] channel."
        );

        return $this;
    }

    /**
     * @api
     * @link https://github.com/timacdonald/log-fake#assertloggedtimes Documentation
     */
    public function assertLoggedTimes(string $level, int $times, ?Closure $callback = null): ChannelFake
    {
        // TODO: flip all exception messages to follow the format "expected x. found y."
        PHPUnit::assertTrue(
            ($count = $this->logged($level, $callback)->count()) === $times,
            "A log with level [{$level}] was logged [{$count}] times instead of an expected [{$times}] times in the [{$this->name}] channel."
        );

        return $this;
    }

    /**
     * @api
     * @link https://github.com/timacdonald/log-fake#assertnotlogged Documentation
     */
    public function assertNotLogged(string $level, ?Closure $callback = null): ChannelFake
    {
        // TODO: deprecate?
        return $this->assertLoggedTimes($level, 0, $callback);
    }

    /**
     * @api
     * @link https://github.com/timacdonald/log-fake#assertnothinglogged Documentation
     */
    public function assertNothingLogged(): ChannelFake
    {
        PHPUnit::assertTrue(
            $this->logs()->isEmpty(),
            "Found [{$this->logs()->count()}] logs in the [{$this->name}] channel. Expected to find [0]."
        );

        return $this;
    }

    /**
     * @api
     * @link https://github.com/timacdonald/log-fake#assertwasforgotten Documentation
     */
    public function assertWasForgotten(): ChannelFake
    {
        PHPUnit::assertTrue(
            $this->timesForgotten > 0,
            "Expected the [{$this->name}] channel to be forgotten at least once. It was forgotten [0] times."
        );

        return $this;
    }

    /**
     * @api
     */
    public function assertWasForgottenTimes(int $times): ChannelFake
    {
        PHPUnit::assertSame(
            $times,
            $this->timesForgotten,
            "Expected the [{$this->name}] channel to be forgotten [{$times}] times. It was forgotten [{$this->timesForgotten}] times."
        );

        return $this;
    }

    /**
     * @api
     */
    public function assertWasNotForgotten(): ChannelFake
    {
        // @deprecate?
        return $this->assertWasForgottenTimes(0);
    }

    /**
     * @api
     * @param array<string, mixed> $context
     */
    public function assertCurrentContext(array $context): ChannelFake
    {
        // TODO: current context for the on-demand channel?
        PHPUnit::assertSame(
            $context,
            $this->currentContext(),
            'Expected to find the context [' . json_encode($context, JSON_THROW_ON_ERROR) . '] in the [' . $this->name . '] channel. Found [' . json_encode((object) $this->currentContext()) . '] instead.'
        );

        return $this;
    }

    /**
     * @api
     * @param array<string, mixed> $context
     */
    public function assertHadContext(array $context): ChannelFake
    {
        PHPUnit::assertTrue(
            $this->allContextInstances()->containsStrict($context),
            'Expected to find the context [' . json_encode($context, JSON_THROW_ON_ERROR) . '] in the [' . $this->name . '] channel but did not.'
        );

        return $this;
    }

    /**
     * @api
     * @param array<string, mixed> $context
     */
    public function assertHadContextAtSetCall(array $context, int $call): ChannelFake
    {
        PHPUnit::assertGreaterThanOrEqual(
            $call,
            $this->allContextInstances()->count(),
            'Expected to find the context set at least [' . $call . '] times in the [' . $this->name . '] channel, but instead found it was set [' . $this->allContextInstances()->count() .'] times.'
        );

        PHPUnit::assertSame(
            $this->allContextInstances()->get($call - 1),
            $context,
            'Expected to find the context [' . json_encode($context, JSON_THROW_ON_ERROR) . '] at set call ['. $call .'] in the [' . $this->name . '] channel but did not.'
        );

        return $this;
    }

    /**
     * @api
     */
    public function assertContextSetTimes(int $times): ChannelFake
    {
        // TODO: allow a 2nd parameter for the specific context you'd like to check against.
        PHPUnit::assertSame(
            $this->allContextInstances()->count(),
            $times,
            'Expected to find the context set [' . $times . '] times in the [' . $this->name . '] channel, but instead found it set [' . $this->allContextInstances()->count() .'] times.'
        );

        return $this;
    }

    /**
     * @api
     * @see Logger::info()
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => array_merge($this->currentContext(), $context),
            'times_channel_has_been_forgotten_at_time_of_writing_log' => $this->timesForgotten,
            'channel' => $this->name,
        ];
    }

    /**
     * @api
     * @see Logger::write()
     * @param array<string, mixed> $context
     */
    public function write($level, $message, array $context = []): void
    {
        $this->log($level, $message, $context);
    }

    /**
     * @api
     * @see Logger::withContext()
     * @param array<string, mixed> $context
     */
    public function withContext(array $context = []): ChannelFake
    {
        $this->context[] = array_merge($this->currentContext(), $context);

        return $this;
    }

    /**
     * @api
     * @see Logger::withoutContext()
     */
    public function withoutContext(): ChannelFake
    {
        $this->context[] = [];

        return $this;
    }

    /**
     * @api
     * @see Logger::listen()
     */
    public function listen(Closure $callback): void
    {
        //
    }

    /**
     * @api
     * @see Logger::getLogger()
     */
    public function getLogger(): ChannelFake
    {
        return $this;
    }

    /**
     * @api
     * @see Logger::getEventDispatcher()
     */
    public function getEventDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * @api
     * @see Logger::setEventDispatcher()
     */
    public function setEventDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @internal
     * @return Collection<int, array{ level: mixed, message: string, context: array<string, mixed>, channel: string, times_channel_has_been_forgotten_at_time_of_writing_log: int }>
     */
    public function logged(string $level, ?Closure $callback = null): Collection
    {
        $callback = $callback ?? fn (): bool => true;

        return $this->logs()
            ->where('level', $level)
            ->filter(fn (array $log): bool => (bool) $callback(
                $log['message'],
                $log['context'],
                $log['times_channel_has_been_forgotten_at_time_of_writing_log']
            ))
            ->values();
    }

    /**
     * @internal
     * @return Collection<int, array{ level: mixed, message: string, context: array<string, mixed>, channel: string, times_channel_has_been_forgotten_at_time_of_writing_log: int }>
     */
    public function logs(): Collection
    {
        return Collection::make($this->logs);
    }

    /**
     * @internal
     */
    public function forget(): ChannelFake
    {
        $this->timesForgotten += 1;

        $this->isCurrentlyForgotten = true;

        return $this->clearContext();
    }

    /**
     * @internal
     */
    public function remember(): ChannelFake
    {
        $this->isCurrentlyForgotten = false;

        return $this;
    }

    /**
     * @internal
     */
    public function isCurrentlyForgotten(): bool
    {
        return $this->isCurrentlyForgotten;
    }

    /**
     * @internal
     */
    public function clearContext(): ChannelFake
    {
        $this->context[] = $this->sentinalContext;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentContext(): array
    {
        /** @var array<string, mixed> */
        $context = Arr::last($this->context) ?? [];

        return $this->isNotSentinalContext($context)
            ? $context
            : [];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function allContextInstances(): Collection
    {
        return Collection::make($this->context)
            ->filter(fn (array $value): bool => $this->isNotSentinalContext($value))
            ->values();
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function isNotSentinalContext(array $context): bool
    {
        return $this->sentinalContext !== $context;
    }
}
