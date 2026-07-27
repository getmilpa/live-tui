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
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Compact status pill: `[label]` colored by the node's semantic role.
 * The TUI analog of a badge/tag chip in a web UI. Single-line by design;
 * the label is truncated to fit the node width minus the brackets so the
 * closing `]` is never dropped. Color is NOT applied here — the final
 * `TuiAnsiPainter` pass picks up the `role` from props and styles it via
 * the `TerminalThemeInterface`. This keeps the renderer pure text so it
 * composes cleanly with the diff buffer.
 *
 * Node props (all optional):
 *  - `label` string  Text inside the badge. Default: ''.
 *  - `role`  string  Semantic role for the theme pass: 'success' | 'warning' | 'error' | 'info' | 'neutral'. Default: 'neutral'.
 *  - `fill`  string  Bracket character set: 'square' (`[ ]`), 'round' (`( )`), 'angle' (`< >`). Default: 'square'.
 */
final class BadgeRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `badge` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'badge';
    }

    /**
     * Draws the pill on one line, colouring it by the node's semantic role.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $label = (string) ($node->props['label'] ?? '');
        $fill = (string) ($node->props['fill'] ?? 'square');
        $width = $context->bounds->width;

        [$open, $close] = match ($fill) {
            'round' => ['(', ')'],
            'angle' => ['<', '>'],
            default => ['[', ']'],
        };

        $innerWidth = max(0, $width - 2);
        $fitted = $this->truncateLabel($label, $innerWidth);
        $line = $open . $fitted . $close;

        $rows = [];
        $rowIndex = 0;
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === $rowIndex ? TuiString::padEnd($line, $width) : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }

    /**
     * Truncates the label to fit inside the brackets without ever dropping
     * the closing bracket — `TuiString::truncate()` alone can eat the whole
     * inner width and leave `[…]` only when the ellipsis fits, but for very
     * narrow widths we want the closing bracket to survive even if the
     * label becomes a bare ellipsis.
     */
    private function truncateLabel(string $label, int $innerWidth): string
    {
        if ($innerWidth <= 0) {
            return '';
        }
        if (TuiString::visibleLength($label) <= $innerWidth) {
            return $label;
        }
        if ($innerWidth === 1) {
            return '…';
        }

        return TuiString::truncate($label, $innerWidth);
    }
}
