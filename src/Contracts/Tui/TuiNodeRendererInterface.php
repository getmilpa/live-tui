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

use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Turns one retained-tree {@see TuiNode} into a painted {@see TuiFrame} —
 * the TUI analog of {@see \Milpa\Live\Contracts\Rendering\ComponentRendererInterface}.
 * Dispatch is by node type via {@see supports()}, resolved through a
 * {@see TuiNodeRendererRegistryInterface}, not by the caller hand-wiring a
 * renderer per node type.
 */
interface TuiNodeRendererInterface
{
    /**
     * Whether this renderer knows how to paint the given node (typically
     * decided by {@see TuiNode::$type}).
     */
    public function supports(TuiNode $node): bool;

    /**
     * Renders the node into a frame sized to `$context->bounds`.
     * Implementations MUST return a {@see TuiFrame} matching
     * `$context->bounds`' width/height exactly, since the caller composes
     * it into a {@see \Milpa\Live\Contracts\Tui\VirtualTerminalBufferInterface}
     * at those bounds without further clamping.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame;
}
