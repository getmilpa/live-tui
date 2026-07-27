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
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Empty lines for vertical spacing. The TUI analog of pi-tui's `Spacer`.
 * Renders `$lines` blank rows (default 1) padded out to the full node width
 * so the layout engine's diff-based repaint has stable rows to compare against.
 *
 * Node props (all optional):
 *  - `lines` int  Number of empty rows to emit. Default: 1.
 */
final class SpacerRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `spacer` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'spacer';
    }

    /**
     * Returns the requested number of blank lines.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $count = max(0, (int) ($node->props['lines'] ?? 1));
        $rows = array_fill(0, $count, '');

        return $this->frame($context->bounds->width, $context->bounds->height, $rows);
    }
}
