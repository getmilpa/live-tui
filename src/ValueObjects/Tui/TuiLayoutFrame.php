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
 * The output of {@see \Milpa\Live\Contracts\Tui\TuiLayoutEngineInterface::layout()}:
 * every visible node's computed bounds, an id-indexed node lookup, and
 * the order nodes should be painted in. Only nodes that survived layout
 * (i.e. were not hidden) are present in `$bounds`/`$nodes`/`$paintOrder` —
 * a node's absence from this frame means it was not laid out, not that
 * it has zero-size bounds.
 */
final readonly class TuiLayoutFrame
{
    /**
     * @param TuiNode                  $root       The tree's root node, as passed to the layout engine.
     * @param array<string, TuiBounds> $bounds     Computed bounds for every visible node, keyed by node id.
     * @param array<string, TuiNode>   $nodes      Every visible node, keyed by its own id.
     * @param array<int, string>       $paintOrder Node ids in the order they MUST be painted; later entries
     *                                             (e.g. overlays) are painted on top of earlier ones.
     */
    public function __construct(
        public TuiNode $root,
        public array $bounds,
        public array $nodes,
        public array $paintOrder,
    ) {
    }

    /**
     * The bounds resolved for this node id, or null when the node is not in the tree.
     *
     * @return TuiBounds|null `null` if `$id` was not laid out (e.g. it was hidden, or is not part of this tree).
     */
    public function boundsFor(string $id): ?TuiBounds
    {
        return $this->bounds[$id] ?? null;
    }

    /**
     * The node with this id, or null when it is not in the tree.
     *
     * @return TuiNode|null `null` if `$id` was not laid out (e.g. it was hidden, or is not part of this tree).
     */
    public function nodeFor(string $id): ?TuiNode
    {
        return $this->nodes[$id] ?? null;
    }
}
