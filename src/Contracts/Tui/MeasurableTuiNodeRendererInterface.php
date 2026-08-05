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

use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * A renderer that can say how tall its node WOULD be, before anything is drawn.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────────────────────────
 *
 * The vertical layout splits the available rows evenly between children that declare no `height`.
 * That is right for a dashboard, where every panel deserves a share, and wrong for a conversation:
 * the more that has been said, the less of each thing you see — exactly when there is most to read.
 * A conversation does not divide, it scrolls.
 *
 * A caller can only scroll if it knows how tall each entry is, and the only honest source of that
 * number is the renderer that will draw it. Anything else is a second implementation of wrapping
 * that agrees with the first until the day it doesn't — and the disagreement shows up as text
 * silently missing from the screen, which is the hardest defect in a TUI to even notice.
 *
 * ── WHY OPTIONAL, AND NOT PART OF THE MAIN INTERFACE ────────────────────────────────────────────
 *
 * Not every renderer can answer. A node that draws a fixed box knows its height; one that streams
 * has no answer until it has streamed. And `TuiNodeRendererInterface` is published — widening it
 * would break every renderer written outside this repository, for a capability most of them do not
 * need. Callers ask with `instanceof` and fall back when the answer isn't available.
 */
interface MeasurableTuiNodeRendererInterface
{
    /**
     * How many rows this node needs at `$width`, with no height limit.
     *
     * The number is what the renderer WOULD produce, not what it will be allowed to draw: the
     * caller is asking precisely so it can decide how much room to grant.
     */
    public function measureHeight(TuiNode $node, int $width): int;
}
