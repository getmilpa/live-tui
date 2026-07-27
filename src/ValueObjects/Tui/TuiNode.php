<?php

declare(strict_types=1);

namespace Milpa\Live\ValueObjects\Tui;

/**
 * One node in the retained TUI tree — the TUI analog of an HTML element.
 * `$type` selects which {@see \Milpa\Live\Contracts\Tui\TuiNodeRendererInterface}
 * paints this node (see {@see \Milpa\Live\Contracts\Tui\TuiNodeRendererInterface::supports()});
 * `$props` is renderer- and layout-engine-defined (e.g. `'hidden'`,
 * `'overlay'`, `'layer'`, `'width'`/`'height'`/`'flex'`, `'gap'`,
 * `'padding'`) rather than a fixed schema, mirroring how HTML attributes
 * are interpreted differently per element/CSS rule.
 */
final readonly class TuiNode
{
    /**
     * @param string               $id        Unique id within the tree; MUST NOT be empty.
     * @param string               $type      The node type, dispatched to a matching node renderer; MUST NOT be empty.
     * @param array<string, mixed> $props     Renderer/layout-engine-defined properties.
     * @param array<int, TuiNode>  $children  Child nodes, laid out according to `$props['layout']` (vertical by default).
     * @param bool                 $focusable Whether this node participates in keyboard focus rotation.
     *
     * @throws \InvalidArgumentException If `$id` or `$type` is empty, or if `$children` contains a non-{@see TuiNode} value.
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $props = [],
        public array $children = [],
        public bool $focusable = false,
    ) {
        if ($id === '' || $type === '') {
            throw new \InvalidArgumentException('TUI node id and type cannot be empty.');
        }

        foreach ($children as $child) {
            if (!$child instanceof self) {
                throw new \InvalidArgumentException('TUI node children must be TuiNode instances.');
            }
        }
    }

    /**
     * Whether the layout engine MUST exclude this node (and its subtree)
     * from {@see \Milpa\Live\ValueObjects\Tui\TuiLayoutFrame}. Reads `props['hidden']`.
     */
    public function hidden(): bool
    {
        return (bool) ($this->props['hidden'] ?? false);
    }

    /**
     * Whether this node is positioned independently of normal document
     * flow (e.g. centered/floating over its parent) rather than
     * participating in the parent's vertical/horizontal allocation.
     * Reads `props['overlay']`.
     */
    public function overlay(): bool
    {
        return (bool) ($this->props['overlay'] ?? false);
    }

    /**
     * The paint-order layer this node belongs to; higher layers are
     * painted on top of lower ones. Reads `props['layer']`, defaulting to
     * `100` for {@see overlay()} nodes and `0` otherwise, so overlays sit
     * above normal content without every overlay node having to set
     * `layer` explicitly.
     */
    public function layer(): int
    {
        return (int) ($this->props['layer'] ?? ($this->overlay() ? 100 : 0));
    }
}
