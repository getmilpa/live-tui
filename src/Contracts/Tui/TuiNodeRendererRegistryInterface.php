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
 * Resolves a {@see TuiNodeRendererInterface} by node via a register +
 * supports-based lookup — the retained-tree counterpart of
 * {@see \Milpa\Live\Contracts\Rendering\ComponentRendererRegistryInterface}
 * on the component-renderer side of the render seam.
 */
interface TuiNodeRendererRegistryInterface
{
    /**
     * Registers a node renderer. Implementations that keep an ordered
     * list and resolve by first match SHOULD prefer the most recently
     * registered renderer when more than one claims to
     * {@see TuiNodeRendererInterface::supports()} the same node.
     */
    public function register(TuiNodeRendererInterface $renderer): void;

    /**
     * Looks up the renderer that {@see TuiNodeRendererInterface::supports()}
     * the given node.
     *
     * @return TuiNodeRendererInterface|null `null` if no registered renderer supports this node — callers decide
     *                                       whether that is fatal (e.g. an unknown node type in the tree).
     */
    public function resolve(TuiNode $node): ?TuiNodeRendererInterface;
}
