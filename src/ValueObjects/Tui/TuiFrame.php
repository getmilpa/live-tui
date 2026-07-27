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
 * A width/height-fixed grid of already-rendered text lines. Pure data --
 * building a normalized (padded/truncated) frame from raw text is the job
 * of {@see \Milpa\Live\Tui\TuiFrameFactory} in the implementation layer,
 * not this value object, so ValueObjects\Tui never has to depend on a
 * concrete string-width helper to be constructed.
 */
final readonly class TuiFrame
{
    /**
     * @param array<int, string> $lines
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $lines,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('TUI frame dimensions cannot be negative.');
        }
    }
}
