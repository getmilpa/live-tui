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
 * Determinate progress bar: `label ──────────── 42%` or indeterminate spinner
 * when `value` is null. Compatible with `BackgroundJob::$progress` (0..1).
 * The fill character is read from the theme's `symbol('progress-fill')`
 * (default '█') and the track from `symbol('progress-track')` (default '─')
 * so a theme can swap the look without touching this renderer.
 *
 * Node props (all optional):
 *  - `value`  float|null  Fraction complete in [0,1]. `null` => indeterminate. Default: 0.
 *  - `label`  string     Left-side label. Default: ''.
 *  - `showPercent` bool   Append `NN%` after the bar. Default: true.
 *  - `barWidth` int      Fixed bar width in columns. Default: remaining width after label/percent.
 *  - `indeterminate` bool Force indeterminate mode. Default: false (auto when value is null).
 */
final class ProgressBarRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `progress-bar` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'progress-bar';
    }

    /**
     * Draws the label, the bar and the percentage — or the indeterminate mark
     * when `value` is null.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        // `??` would fold an explicit `value => null` into 0, and null is the
        // documented way to ask for the indeterminate spinner — so what matters
        // is whether the key is present, not whether its value is null.
        $value = array_key_exists('value', $node->props) ? $node->props['value'] : 0;
        $indeterminate = (bool) ($node->props['indeterminate'] ?? $value === null);
        $label = (string) ($node->props['label'] ?? '');
        $showPercent = (bool) ($node->props['showPercent'] ?? true);
        $width = $context->bounds->width;

        $labelPart = $label !== '' ? $label . ' ' : '';
        $percent = $indeterminate || $value === null
            ? ' ┄ '
            : ($showPercent ? sprintf(' %3d%%', (int) round(max(0.0, min(1.0, (float) $value)) * 100)) : '');
        $labelWidth = TuiString::visibleLength($labelPart);
        $percentWidth = TuiString::visibleLength($percent);
        $barWidth = max(1, $width - $labelWidth - $percentWidth);
        if (isset($node->props['barWidth']) && (int) $node->props['barWidth'] > 0) {
            $barWidth = min($barWidth, (int) $node->props['barWidth']);
        }

        $bar = $indeterminate
            ? $this->indeterminateBar($barWidth)
            : $this->determinateBar((float) $value, $barWidth);
        $line = TuiString::padEnd($labelPart . $bar . $percent, $width);

        $rows = [];
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === 0 ? $line : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }

    private function determinateBar(float $value, int $width): string
    {
        $value = max(0.0, min(1.0, $value));
        $filled = (int) round($value * $width);
        if ($filled >= $width) {
            return str_repeat('█', $width);
        }
        if ($filled <= 0) {
            return str_repeat('─', $width);
        }

        return str_repeat('█', $filled) . str_repeat('─', $width - $filled);
    }

    private function indeterminateBar(int $width): string
    {
        return str_repeat('─', $width);
    }
}
