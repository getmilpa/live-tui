<?php

declare(strict_types=1);

namespace Milpa\Live\ValueObjects\Tui;

/**
 * The context a {@see \Milpa\Live\Contracts\Tui\TuiNodeRendererInterface}
 * renders a single node within: the bounds it must fill, which node (if
 * any) currently has keyboard focus, and — optionally — the full layout
 * frame for renderers that need sibling/ancestor information beyond their
 * own bounds.
 */
final readonly class TuiRenderContext
{
    /**
     * @param TuiBounds           $bounds    The exact region the renderer's output MUST fill.
     * @param string|null         $focusedId The id of the currently focused node, if any.
     * @param TuiLayoutFrame|null $layout    The full layout frame this render is part of, when available.
     */
    public function __construct(
        public TuiBounds $bounds,
        public ?string $focusedId = null,
        public ?TuiLayoutFrame $layout = null,
    ) {
    }

    /**
     * Whether the given node is the one currently focused.
     */
    public function focused(TuiNode $node): bool
    {
        return $this->focusedId === $node->id;
    }
}
