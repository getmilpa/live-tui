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

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\ValueObjects\Tui\BackgroundJob;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Background-job monitor: one line per {@see \Milpa\Live\ValueObjects\Tui\BackgroundJob}
 * with a status icon, its percentage and label, plus the job's last output
 * line underneath. Says so explicitly when there are no jobs.
 */
final class JobMonitorRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `job-monitor` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'job-monitor';
    }

    /**
     * Draws one line per job plus its latest output line.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $jobs = is_array($node->props['jobs'] ?? null) ? $node->props['jobs'] : [];
        $lines = [];
        foreach ($jobs as $job) {
            if ($job instanceof BackgroundJob) {
                $lines[] = sprintf(
                    '%s %3d%% %s',
                    $this->statusIcon($job->status),
                    (int) round($job->progress * 100),
                    $job->label,
                );
                $lastOutput = $job->output !== [] ? $job->output[array_key_last($job->output)] : null;
                if (is_array($lastOutput) && is_string($lastOutput['line'] ?? null)) {
                    $lines[] = '  ↳ ' . $lastOutput['line'];
                }
            }
        }

        if ($lines === []) {
            $lines[] = '◌ No background jobs';
        }

        return $this->frame(
            $context->bounds->width,
            $context->bounds->height,
            $this->boxed('jobs', $lines, $context->bounds->width, $context->bounds->height, $context->focused($node)),
        );
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'running' => '●',
            'done' => '✓',
            'failed' => '✕',
            'canceled' => '◌',
            default => '·',
        };
    }
}
