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
 * The set of rows that changed between two
 * {@see \Milpa\Live\Contracts\Tui\VirtualTerminalBufferInterface} states,
 * as produced by {@see \Milpa\Live\Contracts\Tui\VirtualTerminalBufferInterface::diff()}
 * — the minimal-repaint unit the TUI runtime writes to the real terminal.
 */
final readonly class TuiBufferDiff
{
    /**
     * @param array<int, array{row: int, line: string}> $changes Changed rows, each the row's 0-based index and its
     *                                                           full new line content.
     */
    public function __construct(
        public array $changes,
    ) {
    }

    /**
     * Whether nothing changed (no rows to repaint).
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * Renders this diff as an ANSI escape sequence that repositions the
     * cursor to each changed row (1-based, column 1) and writes its new
     * content — a minimal patch suitable for writing directly to a real
     * terminal instead of redrawing the full screen.
     */
    public function renderAnsiPatch(): string
    {
        $chunks = [];
        foreach ($this->changes as $change) {
            $row = $change['row'] + 1;
            $chunks[] = "\033[{$row};1H" . $change['line'];
        }

        return implode('', $chunks);
    }
}
