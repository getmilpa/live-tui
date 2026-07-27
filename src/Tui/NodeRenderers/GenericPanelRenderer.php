<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Last-resort renderer: accepts ANY node type and draws it as a titled box.
 * It exists so an unknown node degrades into something readable instead of
 * a blank region — register it last, since it matches everything.
 */
final class GenericPanelRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True for every node: this is the fallback renderer, so it must be the
     * last one registered or it will shadow the specific ones.
     */
    public function supports(TuiNode $node): bool
    {
        return true;
    }

    /**
     * Draws the node as a titled box, taking its content from `lines` or
     * `content` and its title from `title`, falling back to the node type.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $title = (string) ($node->props['title'] ?? $node->type);
        $content = $node->props['lines'] ?? $node->props['content'] ?? [];
        if (is_string($content)) {
            $content = explode("\n", $content);
        }
        if (!is_array($content)) {
            $content = [];
        }

        return $this->frame(
            $context->bounds->width,
            $context->bounds->height,
            $this->boxed($title, array_map('strval', $content), $context->bounds->width, $context->bounds->height, $context->focused($node)),
        );
    }
}
