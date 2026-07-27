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

namespace Milpa\Live\Contracts\Tui;

use Milpa\Live\ValueObjects\Tui\BackgroundJob;

/**
 * Owns the lifecycle of {@see BackgroundJob}s a TUI runtime tracks (e.g.
 * long-running commands surfaced in a job monitor panel). Every mutator
 * here returns the job's new state rather than mutating in place, mirroring
 * {@see BackgroundJob}'s own immutability; implementations MAY additionally
 * publish a {@see \Milpa\Live\ValueObjects\Tui\TuiEvent} through a
 * {@see TuiEventBusInterface} for each transition so a job-monitor node
 * renderer can react without polling.
 */
interface BackgroundJobManagerInterface
{
    /**
     * Starts and registers a new job in `running` status.
     */
    public function start(string $label, string $command = ''): BackgroundJob;

    /**
     * Appends one line of output to an existing job.
     *
     * @throws \RuntimeException If `$jobId` is not a known job.
     */
    public function appendOutput(string $jobId, string $line, string $stream = 'stdout'): BackgroundJob;

    /**
     * Updates an existing job's progress. Implementations MUST clamp
     * `$progress` to the `[0.0, 1.0]` range.
     *
     * @throws \RuntimeException If `$jobId` is not a known job.
     */
    public function progress(string $jobId, float $progress): BackgroundJob;

    /**
     * Marks an existing job `done` with the given exit code.
     *
     * @throws \RuntimeException If `$jobId` is not a known job.
     */
    public function finish(string $jobId, int $exitCode = 0): BackgroundJob;

    /**
     * Marks an existing job `failed` with a reason and exit code.
     *
     * @throws \RuntimeException If `$jobId` is not a known job.
     */
    public function fail(string $jobId, string $reason, int $exitCode = 1): BackgroundJob;

    /**
     * Marks an existing job `canceled`.
     *
     * @throws \RuntimeException If `$jobId` is not a known job.
     */
    public function cancel(string $jobId): BackgroundJob;

    /**
     * The job with this id, or null when it is unknown.
     *
     * @return BackgroundJob|null `null` if `$jobId` is not a known job — unlike the mutators, this lookup
     *                            does not throw.
     */
    public function get(string $jobId): ?BackgroundJob;

    /**
     * All tracked jobs, in registration order.
     *
     * @return array<int, BackgroundJob>
     */
    public function all(): array;
}
