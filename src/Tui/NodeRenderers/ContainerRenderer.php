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
 * Structural node types (`app`, `split`, `stack`, `container`) that own
 * layout but paint nothing themselves — their frame is empty on purpose,
 * because the layout engine positions the children that do the painting.
 */
final class ContainerRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True for the structural node types `app`, `split`, `stack` and
     * `container`.
     */
    public function supports(TuiNode $node): bool
    {
        return in_array($node->type, ['app', 'split', 'stack', 'container'], true);
    }

    /**
     * Returns an empty frame: this node contributes layout, not pixels.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        return $this->frame($context->bounds->width, $context->bounds->height, []);
    }
}
