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

use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiBufferDiff;
use Milpa\Live\ValueObjects\Tui\TuiFrame;

/**
 * An off-screen, fixed-size character grid that painted {@see TuiFrame}s
 * are composited into, so a full retained-tree repaint can be diffed
 * against the previously drawn buffer and only the changed rows written
 * to the real terminal — the mechanism that makes retained-mode TUI
 * rendering cheap enough to run every frame.
 */
interface VirtualTerminalBufferInterface
{
    /**
     * The buffer's fixed column count.
     */
    public function width(): int;

    /**
     * The buffer's fixed row count.
     */
    public function height(): int;

    /**
     * Composites `$frame` into this buffer at `$bounds`, overwriting the
     * cells it covers. Implementations MUST clip silently: any part of
     * `$frame` that falls outside this buffer's `[0, width) x [0, height)`
     * extent is dropped rather than throwing.
     */
    public function writeFrame(TuiBounds $bounds, TuiFrame $frame): void;

    /**
     * The buffer's current contents as one string per row.
     *
     * @return array<int, string>
     */
    public function lines(): array;

    /**
     * Compares this buffer's current contents against another buffer
     * (typically the previous frame's buffer) and returns only the rows
     * that differ, for a minimal-repaint terminal write. `self` here
     * means "any implementation of this interface", not specifically the
     * same concrete class — two different implementations MAY be diffed
     * against each other as long as both answer {@see lines()}.
     */
    public function diff(self $previous): TuiBufferDiff;
}
