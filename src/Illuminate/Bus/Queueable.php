<?php

namespace Illuminate\Bus;

use Closure;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

use function Illuminate\Support\enum_value;

trait Queueable
{
    /**
     * The name of the connection the job should be sent to.
     *
     * @var string|null
     */
    public ?string $connection = null;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public ?string $queue = null;

    /**
     * The number of seconds before the job should be made available.
     *
     * @var \DateTimeInterface|\DateInterval|array|int|null
     */
    public array|int|null|\DateTimeInterface|\DateInterval $delay = null;

    /**
     * Indicates whether the job should be dispatched after all database transactions have committed.
     *
     * @var bool|null
     */
    public ?bool $afterCommit = null;

    /**
     * The middleware the job should be dispatched through.
     *
     * @var array
     */
    public array $middleware = [];

    /**
     * The jobs that should run if this job is successful.
     *
     * @var array
     */
    public array $chained = [];

    /**
     * The name of the connection the chain should be sent to.
     *
     * @var string|null
     */
    public ?string $chainConnection = null;

    /**
     * The name of the queue the chain should be sent to.
     *
     * @var string|null
     */
    public ?string $chainQueue = null;

    /**
     * The callbacks to be executed on chain failure.
     *
     * @var array|null
     */
    public ?array $chainCatchCallbacks = null;

    /**
     * Set the desired connection for the job.
     *
     * @param  \BackedEnum|string|null  $connection
     * @return $this
     */
    public function onConnection(\BackedEnum|string|null $connection): static
    {
        $this->connection = enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     *
     * @param  \BackedEnum|string|null  $queue
     * @return $this
     */
    public function onQueue(\BackedEnum|string|null $queue): static
    {
        $this->queue = enum_value($queue);

        return $this;
    }

    /**
     * Set the desired connection for the chain.
     *
     * @param  \BackedEnum|string|null  $connection
     * @return $this
     */
    public function allOnConnection(\BackedEnum|string|null $connection): static
    {
        $resolvedConnection = enum_value($connection);

        $this->chainConnection = $resolvedConnection;
        $this->connection = $resolvedConnection;

        return $this;
    }

    /**
     * Set the desired queue for the chain.
     *
     * @param  \BackedEnum|string|null  $queue
     * @return $this
     */
    public function allOnQueue(\BackedEnum|string|null $queue): static
    {
        $resolvedQueue = enum_value($queue);

        $this->chainQueue = $resolvedQueue;
        $this->queue = $resolvedQueue;

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     *
     * @param  \DateInterval|\DateTimeInterface|int|array|null  $delay
     * @return $this
     */
    public function delay(\DateInterval|\DateTimeInterface|int|array|null $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Set the delay for the job to zero seconds.
     *
     * @return $this
     */
    public function withoutDelay(): static
    {
        $this->delay = 0;

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after all database transactions have committed.
     *
     * @return $this
     */
    public function afterCommit(): static
    {
        $this->afterCommit = true;

        return $this;
    }

    /**
     * Indicate that the job should not wait until database transactions have been committed before dispatching.
     *
     * @return $this
     */
    public function beforeCommit(): static
    {
        $this->afterCommit = false;

        return $this;
    }

    /**
     * Specify the middleware the job should be dispatched through.
     *
     * @param  object|array  $middleware
     * @return $this
     */
    public function through(object|array $middleware): static
    {
        $this->middleware = Arr::wrap($middleware);

        return $this;
    }

    /**
     * Set the jobs that should run if this job is successful.
     *
     * @param  array  $chain
     * @return $this
     */
    public function chain(array $chain): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect($chain));

        $this->chained = $jobs->map(function ($job) {
            return $this->serializeJob($job);
        })->all();

        return $this;
    }

    /**
     * Prepend a job to the current chain so that it is run after the currently running job.
     *
     * @param  mixed  $job
     * @return $this
     */
    public function prependToChain(mixed $job): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect([$job]));

        $this->chained = Arr::prepend($this->chained, $this->serializeJob($jobs->first()));

        return $this;
    }

    /**
     * Append a job to the end of the current chain.
     *
     * @param  mixed  $job
     * @return $this
     */
    public function appendToChain(mixed $job): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect([$job]));

        $this->chained = array_merge($this->chained, [$this->serializeJob($jobs->first())]);

        return $this;
    }

    /**
     * Serialize a job for queuing.
     *
     * @param  mixed  $job
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function serializeJob(mixed $job): string
    {
        if ($job instanceof Closure) {
            if (! class_exists(CallQueuedClosure::class)) {
                throw new RuntimeException(
                    'To enable support for closure jobs, please install the illuminate/queue package.'
                );
            }

            $job = CallQueuedClosure::create($job);
        }

        return serialize($job);
    }

    /**
     * Dispatch the next job on the chain.
     *
     * @return void
     */
    public function dispatchNextJobInChain(): void
    {
        if (! empty($this->chained)) {
            dispatch(tap(unserialize(array_shift($this->chained)), function ($next) {
                $next->chained = $this->chained;

                $next->onConnection($next->connection ?: $this->chainConnection);
                $next->onQueue($next->queue ?: $this->chainQueue);

                $next->chainConnection = $this->chainConnection;
                $next->chainQueue = $this->chainQueue;
                $next->chainCatchCallbacks = $this->chainCatchCallbacks;
            }));
        }
    }

    /**
     * Invoke all of the chain's failed job callbacks.
     *
     * @param  \Throwable|null  $e
     * @return void
     */
    public function invokeChainCatchCallbacks(\Throwable|null $e): void
    {
        collect($this->chainCatchCallbacks)->each(function ($callback) use ($e) {
            $callback($e);
        });
    }

    /**
     * Assert that the job has the given chain of jobs attached to it.
     *
     * @param  array  $expectedChain
     * @return void
     */
    public function assertHasChain(array $expectedChain): void
    {
        PHPUnit::assertTrue(
            collect($expectedChain)->isNotEmpty(),
            'The expected chain can not be empty.'
        );

        if (collect($expectedChain)->contains(fn ($job) => is_object($job))) {
            $expectedChain = collect($expectedChain)->map(fn ($job) => serialize($job))->all();
        } else {
            $chain = collect($this->chained)->map(fn ($job) => get_class(unserialize($job)))->all();
        }

        PHPUnit::assertTrue(
            $expectedChain === ($chain ?? $this->chained),
            'The job does not have the expected chain.'
        );
    }

    /**
     * Assert that the job has no remaining chained jobs.
     *
     * @return void
     */
    public function assertDoesntHaveChain(): void
    {
        PHPUnit::assertEmpty($this->chained, 'The job has chained jobs.');
    }
}
