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

namespace Milpa\Live\ValueObjects\Tui;

/**
 * An immutable snapshot of one background job's state, as tracked by a
 * {@see \Milpa\Live\Contracts\Tui\BackgroundJobManagerInterface}.
 * `$status` MUST be one of `'running'`, `'done'`, `'failed'`, or
 * `'canceled'`; every `with*()` method returns a new instance rather than
 * mutating this one, so callers can never observe a job partway through
 * a transition.
 */
final readonly class BackgroundJob
{
    /**
     * @param string                                          $id       A unique job id; MUST NOT be empty.
     * @param string                                          $label    Human-readable job description.
     * @param string                                          $status   One of `'running'`, `'done'`, `'failed'`, `'canceled'`.
     * @param string                                          $command  The command/operation this job represents, if applicable.
     * @param float                                           $progress Fractional completion in `[0.0, 1.0]`.
     * @param array<int, array{stream: string, line: string}> $output   Captured output lines in emission order.
     * @param int|null                                        $exitCode Process exit code, once the job has finished/failed/been canceled.
     * @param string|null                                     $error    Failure reason, when `$status` is `'failed'`.
     * @param array<string, mixed>                            $meta     Caller-defined extra context beyond the fields above.
     *
     * @throws \InvalidArgumentException If `$id` is empty.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public string $command = '',
        public float $progress = 0.0,
        public array $output = [],
        public ?int $exitCode = null,
        public ?string $error = null,
        public array $meta = [],
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Background job id cannot be empty.');
        }
    }

    /**
     * Returns a copy with one more output line appended.
     */
    public function withOutput(string $line, string $stream = 'stdout'): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            status: $this->status,
            command: $this->command,
            progress: $this->progress,
            output: [...$this->output, ['stream' => $stream, 'line' => $line]],
            exitCode: $this->exitCode,
            error: $this->error,
            meta: $this->meta,
        );
    }

    /**
     * Returns a copy with progress updated, clamped to `[0.0, 1.0]`.
     */
    public function withProgress(float $progress): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            status: $this->status,
            command: $this->command,
            progress: max(0.0, min(1.0, $progress)),
            output: $this->output,
            exitCode: $this->exitCode,
            error: $this->error,
            meta: $this->meta,
        );
    }

    /**
     * Returns a copy transitioned to a new status. Forces `$progress` to
     * `1.0` when `$status` is one of the terminal statuses (`'done'`,
     * `'failed'`, `'canceled'`), so a finished job is never left showing
     * partial progress.
     */
    public function withStatus(string $status, ?int $exitCode = null, ?string $error = null): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            status: $status,
            command: $this->command,
            progress: in_array($status, ['done', 'failed', 'canceled'], true) ? 1.0 : $this->progress,
            output: $this->output,
            exitCode: $exitCode,
            error: $error,
            meta: $this->meta,
        );
    }
}
