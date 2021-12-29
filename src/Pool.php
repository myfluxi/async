<?php

namespace Spatie\Async;

use ArrayAccess;
use InvalidArgumentException;
use Spatie\Async\Process\ParallelProcess;
use Spatie\Async\Process\Runnable;
use Spatie\Async\Process\SynchronousProcess;
use Spatie\Async\Runtime\ParentRuntime;

class Pool implements ArrayAccess
{
    public static $forceSynchronous = false;

    protected $concurrency = 20;
    protected $tasksPerProcess = 1;
    protected $timeout = 300;
    protected $sleepTime = 50000;

    /** @var \Spatie\Async\Process\Runnable[] */
    protected $queue = [];

    /** @var \Spatie\Async\Process\Runnable[] */
    protected $inProgress = [];

    /** @var \Spatie\Async\Process\Runnable[] */
    protected $finished = [];

    /** @var \Spatie\Async\Process\Runnable[] */
    protected $failed = [];

    /** @var \Spatie\Async\Process\Runnable[] */
    protected $timeouts = [];

    protected $results = [];

    protected $status;

    protected $stopped = false;

    protected $binary = PHP_BINARY;
    protected $autoloader = null;
    protected $childProcessScript = null;

    protected array $extraChildProcessArgs = [];

    public function __construct()
    {
        if (static::isSupported()) {
            $this->registerListener();
        }

        $this->status = new PoolStatus($this);
    }

    /**
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    public static function isSupported(): bool
    {
        return
            function_exists('pcntl_async_signals')
            && function_exists('posix_kill')
            && function_exists('proc_open')
            && ! self::$forceSynchronous;
    }

    public function forceSynchronous(): self
    {
        self::$forceSynchronous = true;

        return $this;
    }

    public function concurrency(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    public function timeout(float $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @param string $autoloader
     *
     * @return $this
     */
    public function autoload(string $autoloader): self
    {
        ParentRuntime::init($autoloader);

        return $this;
    }

    public function sleepTime(int $sleepTime): self
    {
        $this->sleepTime = $sleepTime;

        return $this;
    }

    public function withBinary(string $binary): self
    {
        $this->binary = $binary;

        return $this;
    }

    public function withAutoloader(string $autoloader): self
    {
        $this->autoloader = $autoloader;

        return $this;
    }

    public function withChildProcessScript(string $childProcessScript, array $extraArgs = []): self
    {
        $this->childProcessScript = $childProcessScript;
        $this->extraChildProcessArgs = $extraArgs;

        return $this;
    }

    public function notify()
    {
        if (count($this->inProgress) >= $this->concurrency) {
            return;
        }

        $process = array_shift($this->queue);

        if (! $process) {
            return;
        }

        $this->putInProgress($process);
    }

    /**
     * @param \Spatie\Async\Process\Runnable|callable $process
     * @param int|null $outputLength
     *
     * @return \Spatie\Async\Process\Runnable
     */
    public function add($process, ?int $outputLength = null): Runnable
    {
        if (! is_callable($process) && ! $process instanceof Runnable) {
            throw new InvalidArgumentException('The process passed to Pool::add should be callable.');
        }

        if (! $process instanceof Runnable) {
            $process = ParentRuntime::createProcess(
                $process,
                $outputLength,
                $this->binary,
                $this->autoloader,
                $this->childProcessScript,
                $this->extraChildProcessArgs
            );
        }

        $this->putInQueue($process);

        return $process;
    }

    /**
     * @param callable|null $intermediateCallback
     *
     * @return array|void
     */
    public function baseWait(?callable $intermediateCallback = null) {
        foreach ($this->inProgress as $process) {
            if ($process->getCurrentExecutionTime() > $this->timeout) {
                $this->markAsTimedOut($process);
            }

            if ($process instanceof SynchronousProcess) {
                $this->markAsFinished($process);
            }
        }

        if ($this->inProgress) {
            if ($intermediateCallback) {
                call_user_func_array($intermediateCallback, [$this]);
            }
        }else{
            return $this->results;
        }
    }

    public function wait(?callable $intermediateCallback = null): array {
        while ($this->inProgress) {
            $this->baseWait($intermediateCallback);
            usleep($this->sleepTime);
        }

        return $this->results;
    }

    public function putInQueue(Runnable $process)
    {
        $this->queue[$process->getId()] = $process;

        $this->notify();
    }

    public function putInProgress(Runnable $process)
    {
        if ($this->stopped) {
            return;
        }

        if ($process instanceof ParallelProcess) {
            $process->getProcess()->setTimeout($this->timeout);
        }

        $process->start();

        unset($this->queue[$process->getId()]);

        $this->inProgress[$process->getPid()] = $process;
    }

    public function markAsFinished(Runnable $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $this->notify();

        $this->results[] = $process->triggerSuccess();

        $this->finished[$process->getPid()] = $process;
    }

    public function markAsTimedOut(Runnable $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $process->stop();

        $process->triggerTimeout();
        $this->timeouts[$process->getPid()] = $process;

        $this->notify();
    }

    public function markAsFailed(Runnable $process)
    {
        unset($this->inProgress[$process->getPid()]);

        $this->notify();

        $process->triggerError();

        $this->failed[$process->getPid()] = $process;
    }

    public function offsetExists($offset)
    {
        // TODO

        return false;
    }

    public function offsetGet($offset)
    {
        // TODO
    }

    public function offsetSet($offset, $value)
    {
        $this->add($value);
    }

    public function offsetUnset($offset)
    {
        // TODO
    }

    /**
     * @return \Spatie\Async\Process\Runnable[]
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    /**
     * @return \Spatie\Async\Process\Runnable[]
     */
    public function getInProgress(): array
    {
        return $this->inProgress;
    }

    /**
     * @return \Spatie\Async\Process\Runnable[]
     */
    public function getFinished(): array
    {
        return $this->finished;
    }

    /**
     * @return \Spatie\Async\Process\Runnable[]
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return \Spatie\Async\Process\Runnable[]
     */
    public function getTimeouts(): array
    {
        return $this->timeouts;
    }
    
    /**
     * @return int
     */
    public function getSleepTime(): int {
        return $this->sleepTime;
    }

    public function status(): PoolStatus
    {
        return $this->status;
    }

    protected function registerListener()
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function ($signo, $status) {
            while (true) {
                $pid = pcntl_waitpid(-1, $processState, WNOHANG | WUNTRACED);

                if ($pid <= 0) {
                    break;
                }

                $process = $this->inProgress[$pid] ?? null;

                if (! $process) {
                    continue;
                }

                if ($status['status'] === 0) {
                    $this->markAsFinished($process);

                    continue;
                }

                $this->markAsFailed($process);
            }
        });
    }

    public function stop()
    {
        $this->stopped = true;
    }
}
