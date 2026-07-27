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
 * A rectangular region in terminal cell coordinates (`$x`/`$y` from the
 * top-left, in columns/rows), used throughout the TUI layer for viewport,
 * node, and overlay placement.
 */
final readonly class TuiBounds
{
    /**
     * @throws \InvalidArgumentException If any of `$x`, `$y`, `$width`, `$height` is negative.
     */
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        if ($x < 0 || $y < 0 || $width < 0 || $height < 0) {
            throw new \InvalidArgumentException('TUI bounds cannot be negative.');
        }
    }

    /**
     * The x-coordinate just past this region's right edge (`$x + $width`).
     */
    public function right(): int
    {
        return $this->x + $this->width;
    }

    /**
     * The y-coordinate just past this region's bottom edge (`$y + $height`).
     */
    public function bottom(): int
    {
        return $this->y + $this->height;
    }

    /**
     * Returns a new region shrunk by `$amount` on every side (e.g. for
     * padding). `$amount` is clamped to non-negative, and the resulting
     * width/height are clamped to zero rather than going negative when
     * `$amount` exceeds half of this region's size.
     */
    public function inset(int $amount): self
    {
        $amount = max(0, $amount);

        return new self(
            x: $this->x + $amount,
            y: $this->y + $amount,
            width: max(0, $this->width - ($amount * 2)),
            height: max(0, $this->height - ($amount * 2)),
        );
    }
}
