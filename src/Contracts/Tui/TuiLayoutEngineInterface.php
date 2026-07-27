<?php

declare(strict_types=1);

namespace Milpa\Live\Contracts\Tui;

use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiLayoutFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Computes per-node screen-space bounds for a retained {@see TuiNode} tree
 * within a viewport — the TUI analog of a flexbox-style layout pass.
 * Hidden nodes ({@see TuiNode::hidden()}) MUST be excluded from the
 * result. Overlay nodes ({@see TuiNode::overlay()}) are positioned
 * independently of normal document flow (e.g. centered/floating over
 * their parent) and MUST still be included, with their paint order
 * placed above non-overlay siblings via {@see TuiNode::layer()}.
 */
interface TuiLayoutEngineInterface
{
    /**
     * Lays out `$root` and its visible descendants within `$viewport`.
     *
     * @return TuiLayoutFrame Per-node bounds and nodes keyed by id, plus a paint order where later
     *                        entries MUST be drawn on top of earlier ones.
     */
    public function layout(TuiNode $root, TuiBounds $viewport): TuiLayoutFrame;
}
