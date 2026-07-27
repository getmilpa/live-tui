<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Animated Milpa "M" logo rendered as a 5x5 grid of corn kernels.
 * This renderer deliberately emits plain text; TuiAnsiPainter applies
 * the brand color after the retained frame has been composed.
 *
 * The M shape (matching the SVG symbol's rect positions on a 5×5 grid):
 *
 *     G · · · G     row 0: corners
 *     G G · G G     row 1: outer + inner V
 *     G · G · G     row 2: outer + center
 *     G · · · G     row 3: outer only
 *     G · · · G     row 4: base
 *
 * As the frame advances, grains grow from the bottom corners toward the
 * center, going from `·` to `◆`. After all grains are lit, the logo holds
 * steady; the caller can reset the frame to replay it.
 *
 * Node props (all optional):
 *  - `frame`     int  Animation frame (0..13). 0 = empty grid, 13 = full M.
 *  - `grain`     string  Character for a lit grain. Default: '◆'.
 *  - `empty`     string  Character for an unlit cell. Default: '·'.
 *  - `label`     string  Text label shown below the grid. Default: 'milpa'.
 *  - `animate`   bool   Animate (grain-by-grain). Default: true.
 */
final class MilpaLogoRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /** @var array<int, array<int, bool>> 5×5 grid — true = grain present */
    private const GRID = [
        [true,  false, false, false, true],  // row 0: top corners
        [true,  true,  false, true,  true],   // row 1: outer + inner V
        [true,  false, true,  false, true],   // row 2: outer + center
        [true,  false, false, false, true],   // row 3: outer only
        [true,  false, false, false, true],   // row 4: base
    ];

    /** @var array<int, array{0: int, 1: int}> */
    private const REVEAL_ORDER = [
        [4, 0], [4, 4], [3, 0], [3, 4], [2, 0], [2, 4], [1, 0],
        [1, 4], [0, 0], [0, 4], [1, 1], [1, 3], [2, 2],
    ];

    private const GRAIN_COUNT = 13;

    /**
     * True only for `milpa-logo` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'milpa-logo';
    }

    /**
     * Draws the current animation frame of the kernel grid.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $frame = (int) ($node->props['frame'] ?? self::GRAIN_COUNT);
        $grain = TuiString::slice((string) ($node->props['grain'] ?? '◆'), 1) ?: '◆';
        $empty = TuiString::slice((string) ($node->props['empty'] ?? '·'), 1) ?: '·';
        $label = (string) ($node->props['label'] ?? 'milpa');
        $animate = (bool) ($node->props['animate'] ?? true);
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        $lines = [];
        $planted = $animate ? min(self::GRAIN_COUNT, max(0, $frame)) : self::GRAIN_COUNT;
        $visible = [];
        foreach (array_slice(self::REVEAL_ORDER, 0, $planted) as [$row, $col]) {
            $visible["{$row}:{$col}"] = true;
        }

        for ($row = 0; $row < 5; $row++) {
            $cells = [];
            for ($col = 0; $col < 5; $col++) {
                $isGrain = self::GRID[$row][$col];
                $cells[] = !$isGrain
                    ? ' '
                    : (isset($visible["{$row}:{$col}"]) ? $grain : $empty);
            }
            $gridLine = implode(' ', $cells);
            $gridWidth = TuiString::visibleLength($gridLine);
            $padLeft = max(0, (int) floor(($width - $gridWidth) / 2));
            $lines[] = TuiString::padEnd(
                TuiString::truncate(str_repeat(' ', $padLeft) . $gridLine, $width, ''),
                $width,
            );
        }

        if ($label !== '') {
            $label = TuiString::truncate($label, $width, '');
            $labelPad = max(0, (int) floor(($width - TuiString::visibleLength($label)) / 2));
            $lines[] = TuiString::padEnd(str_repeat(' ', $labelPad) . $label, $width);
        }

        // Fill remaining height with blanks.
        while (count($lines) < $height) {
            $lines[] = str_repeat(' ', $width);
        }

        return $this->frame($width, $height, array_slice($lines, 0, $height));
    }
}
