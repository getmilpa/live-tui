<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Single-row status bar: an indicator and left-hand label, a rule that grows
 * to fill the gap, and right-hand key hints. Truncation here loses the key
 * hints, so the fill is what absorbs the width, never the text.
 */
final class StatusBarRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `status-bar` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'status-bar';
    }

    /**
     * Draws the bar, giving the fill whatever width is left after both labels so
     * neither the status nor the key hints get truncated.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $indicator = (string) ($node->props['indicator'] ?? '●');
        $left = (string) ($node->props['left'] ?? 'Ready');
        if ($indicator !== '') {
            $left = $indicator . ' ' . $left;
        }
        $right = (string) ($node->props['right'] ?? '');
        $right = $right !== '' ? '⌘ ' . $right : '';
        $available = $context->bounds->width - \Milpa\Live\Tui\TuiString::visibleLength($left) - \Milpa\Live\Tui\TuiString::visibleLength($right);
        $space = max(1, $available);

        return $this->frame($context->bounds->width, $context->bounds->height, [
            $this->fit($left . ($space > 3 ? str_repeat('─', $space) : str_repeat(' ', $space)) . $right, $context->bounds->width),
        ]);
    }
}
