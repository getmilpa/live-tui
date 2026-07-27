<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Horizontal divider rule with an optional inline label.
 *   `── label ────────────`
 * Renders a single row; vertical orientation emits one column of `│` chars
 * when the node bounds are taller than wide (rare but kept for symmetry).
 *
 * Node props (all optional):
 *  - `label`  string Inline label rendered left-aligned after the rule. Default: ''.
 *  - `char`   string Rule character. Default: '─' (horizontal), '│' (vertical).
 *  - `align`  string 'left' | 'center' | 'right' — label alignment within the rule. Default: 'left'.
 *  - `orientation` string 'horizontal' | 'vertical'. Default: 'horizontal'.
 */
final class DividerRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `divider` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'divider';
    }

    /**
     * Draws the rule across the full width, splitting it around the label when
     * the node declares one.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $orientation = (string) ($node->props['orientation'] ?? 'horizontal');
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        if ($orientation === 'vertical') {
            $char = (string) ($node->props['char'] ?? '│');
            $col = max(0, intdiv($width, 2));
            $rows = array_fill(0, $height, str_repeat(' ', $col) . $char . str_repeat(' ', max(0, $width - $col - 1)));

            return $this->frame($width, $height, $rows);
        }

        $char = (string) ($node->props['char'] ?? '─');
        $label = (string) ($node->props['label'] ?? '');
        $align = (string) ($node->props['align'] ?? 'left');

        $rows = [];
        $row = $this->horizontalRule($char, $label, $align, $width);
        for ($i = 0; $i < $height; $i++) {
            $rows[] = $i === 0 ? $row : str_repeat($char, $width);
        }

        return $this->frame($width, $height, $rows);
    }

    private function horizontalRule(string $char, string $label, string $align, int $width): string
    {
        if ($label === '') {
            return str_repeat($char, $width);
        }
        $labelWidth = TuiString::visibleLength($label);
        $gap = max(0, $width - $labelWidth - 2);

        return match ($align) {
            'center' => str_repeat($char, max(0, (int) floor($gap / 2))) . ' ' . $label . ' ' . str_repeat($char, max(0, (int) ceil($gap / 2))),
            'right' => str_repeat($char, $gap) . ' ' . $label . ' ' . $char,
            default => $char . ' ' . $label . ' ' . str_repeat($char, $gap),
        };
    }
}
