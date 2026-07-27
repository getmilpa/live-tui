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
 * A padded container with optional border and background fill. The TUI
 * analog of pi-tui's `Box`. Unlike `GenericPanelRenderer` (which draws a
 * titled chrome box around static `lines` props), this renderer is a pure
 * wrapper: the children are laid out by `SimpleTuiLayoutEngine` into the
 * inset region this renderer declares via `padding`/`border`, and this
 * renderer only paints the chrome (border + background fill) around the
 * already-composed child frames.
 *
 * Node props (all optional):
 *  - `padding`  int  Inner padding on every side. Default: 0.
 *  - `paddingX` int  Horizontal padding (wins over `padding`). Default: 0.
 *  - `paddingY` int  Vertical padding. Default: 0.
 *  - `border`   bool Draw a border around the box. Default: false.
 *  - `title`    string  Inline title in the top border (only if `border`). Default: ''.
 *  - `bg`       string  Background fill character for the inner region. Default: ' '.
 *  - `focused`  bool   Override focus state (otherwise context.focused()). Default: context.focused().
 */
final class BoxRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `box` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'box';
    }

    /**
     * Draws the border, padding and fill, leaving the interior to the children
     * the layout engine positions inside these bounds.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $paddingX = (int) ($node->props['paddingX'] ?? $node->props['padding'] ?? 0);
        $paddingY = (int) ($node->props['paddingY'] ?? $node->props['padding'] ?? 0);
        $border = (bool) ($node->props['border'] ?? false);
        $title = (string) ($node->props['title'] ?? '');
        $bg = (string) ($node->props['bg'] ?? ' ');
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        $borderW = $border ? 1 : 0;
        $innerX = $borderW + $paddingX;
        $innerY = $borderW + $paddingY;
        $innerW = max(0, $width - ($innerX * 2));
        $innerH = max(0, $height - ($innerY * 2));

        $rows = array_fill(0, $height, str_repeat($bg[0] ?? ' ', $width));

        if ($border) {
            $rows = $this->paintBorder($rows, $width, $height, $title, $focused);
        }

        // Fill the inner region with the background character. The actual
        // child content is composed on top of this by RetainedTuiRenderer
        // because the layout engine allocates child bounds inside this
        // node's inset — this renderer only owns the chrome + background.
        $innerFill = str_repeat($bg[0] ?? ' ', $innerW);
        for ($y = $innerY; $y < $innerY + $innerH && $y < $height - $borderW; $y++) {
            $cells = TuiString::cells($rows[$y]);
            $beforeCells = array_slice($cells, 0, $innerX);
            $afterCells = array_slice($cells, $innerX + $innerW);
            $rows[$y] = implode('', $beforeCells) . $innerFill . implode('', $afterCells);
        }

        return $this->frame($width, $height, $rows);
    }

    /**
     * @param array<int, string> $rows
     *
     * @return array<int, string>
     */
    private function paintBorder(array $rows, int $width, int $height, string $title, bool $focused): array
    {
        if ($width < 2 || $height < 2) {
            return $rows;
        }

        $top = ($focused ? '╭' : '┌')
            . $this->titledRule($width - 2, $title, $focused)
            . ($focused ? '╮' : '┐');
        $bottom = ($focused ? '╰' : '└')
            . str_repeat('─', $width - 2)
            . ($focused ? '╯' : '┘');
        $side = $focused ? '│' : '│';

        $rows[0] = TuiString::padEnd($top, $width);
        $rows[$height - 1] = TuiString::padEnd($bottom, $width);
        for ($y = 1; $y < $height - 1; $y++) {
            $cells = TuiString::cells($rows[$y]);
            if (count($cells) >= $width) {
                $middle = array_slice($cells, 1, $width - 2);
            } else {
                $middle = array_fill(0, max(0, $width - 2 - (count($cells) - 1)), ' ');
                $middle = array_slice(array_merge(array_slice($cells, 1), $middle), 0, $width - 2);
            }
            $rows[$y] = $side . implode('', $middle) . $side;
        }

        return $rows;
    }

    private function titledRule(int $length, string $title, bool $focused): string
    {
        if ($title === '' || $length < 5) {
            return str_repeat('─', $length);
        }

        $title = TuiString::truncate($title, max(1, $length - 4), '');
        $titleWidth = TuiString::visibleLength($title);
        $marker = $focused ? '› ' : '  ';
        $suffix = max(0, $length - $titleWidth - 4);

        return '─' . $marker . $title . ' ' . str_repeat('─', $suffix);
    }
}
