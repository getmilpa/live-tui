<?php

/**
 * This file is part of Milpa Live TUI — the terminal transport layer (retained-mode runtime, ANSI painting, node rendering) of the Milpa PHP framework live component system.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/live-tui
 */

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\BackgroundJobManagerInterface;
use Milpa\Live\Contracts\Tui\TuiEventBusInterface;
use Milpa\Live\ValueObjects\Tui\BackgroundJob;
use Milpa\Live\ValueObjects\Tui\TuiEvent;

/**
 * Tracks long-running work so the interface can show progress. It records job
 * state — it does not spawn processes: the caller runs the work and reports
 * output, progress and outcome back here.
 */
final class InMemoryBackgroundJobManager implements BackgroundJobManagerInterface
{
    /**
     * @var array<string, BackgroundJob>
     */
    private array $jobs = [];

    public function __construct(
        private readonly ?TuiEventBusInterface $events = null,
    ) {
    }

    /**
     * Records a new running job and returns it.
     */
    public function start(string $label, string $command = ''): BackgroundJob
    {
        $job = new BackgroundJob(
            id: 'job-' . bin2hex(random_bytes(4)),
            label: $label,
            status: 'running',
            command: $command,
        );

        return $this->store($job, 'job.started');
    }

    /**
     * Appends one output line to the job, tagged with the stream it came from.
     */
    public function appendOutput(string $jobId, string $line, string $stream = 'stdout'): BackgroundJob
    {
        $job = $this->requireJob($jobId)->withOutput($line, $stream);

        return $this->store($job, 'job.output', ['line' => $line, 'stream' => $stream]);
    }

    /**
     * Updates the job's completion fraction, in [0,1].
     */
    public function progress(string $jobId, float $progress): BackgroundJob
    {
        $job = $this->requireJob($jobId)->withProgress($progress);

        return $this->store($job, 'job.progress', ['progress' => $job->progress]);
    }

    /**
     * Marks the job finished with the given exit code.
     */
    public function finish(string $jobId, int $exitCode = 0): BackgroundJob
    {
        return $this->store($this->requireJob($jobId)->withStatus('done', $exitCode), 'job.finished', ['exitCode' => $exitCode]);
    }

    /**
     * Marks the job failed, recording why and the exit code.
     */
    public function fail(string $jobId, string $reason, int $exitCode = 1): BackgroundJob
    {
        return $this->store($this->requireJob($jobId)->withStatus('failed', $exitCode, $reason), 'job.failed', ['exitCode' => $exitCode, 'reason' => $reason]);
    }

    /**
     * Marks the job cancelled. It records the outcome — it does not stop the work,
     * which the caller owns.
     */
    public function cancel(string $jobId): BackgroundJob
    {
        return $this->store($this->requireJob($jobId)->withStatus('canceled', 130), 'job.canceled', ['exitCode' => 130]);
    }

    /**
     * The job with this id, or null when it is unknown.
     */
    public function get(string $jobId): ?BackgroundJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    /**
     * Every job this manager has seen, finished ones included.
     */
    public function all(): array
    {
        return array_values($this->jobs);
    }

    private function requireJob(string $jobId): BackgroundJob
    {
        return $this->jobs[$jobId] ?? throw new \RuntimeException("Unknown background job: {$jobId}");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(BackgroundJob $job, string $eventType, array $payload = []): BackgroundJob
    {
        $this->jobs[$job->id] = $job;
        $this->events?->publish(TuiEvent::now($eventType, array_merge($payload, [
            'jobId' => $job->id,
            'label' => $job->label,
            'status' => $job->status,
        ]), source: 'background-jobs'));

        return $job;
    }
}
