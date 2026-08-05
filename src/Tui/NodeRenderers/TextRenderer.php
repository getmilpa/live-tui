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

use Milpa\Live\Contracts\Tui\MeasurableTuiNodeRendererInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Renders multi-line text with word wrapping, alignment, and padding.
 * The TUI analog of pi-tui's `Text` component: pure node-in/frame-out,
 * no state, no keyboard handling. Wraps via {@see TuiString::wordwrap()}
 * so multibyte content (accents, emoji) wraps at the same column count
 * ASCII would.
 *
 * Node props (all optional):
 *  - `content`  string  Text to render. Alias: `text`. Default: ''.
 *  - `wrap`     bool    Word-wrap long lines to fit width. Default: true.
 *  - `align`    string  'left' | 'center' | 'right'. Default: 'left'.
 *  - `padding`  int     Horizontal padding (columns, both sides). Default: 0.
 *  - `paddingX` int    Override horizontal padding (wins over `padding`). Default: 0.
 *  - `paddingY` int    Vertical padding (rows, top + bottom). Default: 0.
 *  - `fill`     string  Background fill character for empty cells. Default: ' '.
 */
final class TextRenderer extends AbstractTuiNodeRenderer implements
    TuiNodeRendererInterface,
    MeasurableTuiNodeRendererInterface
{
    /**
     * True only for `text` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'text';
    }

    /**
     * How many rows this text needs at `$width` — the same lines `render()` would produce.
     *
     * It goes through `lineas()`, the one place that decides where the text breaks. Counting it
     * here with a second wrap would agree with `render()` right up until someone changed one of
     * them, and the disagreement would show as text quietly missing from a screen.
     */
    public function measureHeight(TuiNode $node, int $width): int
    {
        $paddingY = (int) ($node->props['paddingY'] ?? 0);

        return \count($this->lineas($node, $width)) + ($paddingY * 2);
    }

    /**
     * The text broken into the lines it will occupy at `$width` — before padding or alignment.
     *
     * @return list<string>
     */
    private function lineas(TuiNode $node, int $width): array
    {
        $content = (string) ($node->props['content'] ?? $node->props['text'] ?? '');
        if ($content === '') {
            return [];
        }
        $wrap = (bool) ($node->props['wrap'] ?? true);
        $paddingX = (int) ($node->props['paddingX'] ?? $node->props['padding'] ?? 0);
        $innerWidth = max(1, $width - ($paddingX * 2));

        return explode("\n", $wrap ? TuiString::wordwrap($content, $innerWidth) : $content);
    }

    /**
     * Draws the wrapped, aligned and padded text within the bounds.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $align = (string) ($node->props['align'] ?? 'left');
        $paddingX = (int) ($node->props['paddingX'] ?? $node->props['padding'] ?? 0);
        $paddingY = (int) ($node->props['paddingY'] ?? 0);

        $innerWidth = max(1, $context->bounds->width - ($paddingX * 2));
        $rawLines = $this->lineas($node, $context->bounds->width);

        $lines = [];
        for ($i = 0; $i < $paddingY; $i++) {
            $lines[] = '';
        }
        foreach ($rawLines as $line) {
            $lines[] = $this->align(TuiString::truncate($line, $innerWidth), $innerWidth, $align);
        }
        for ($i = 0; $i < $paddingY; $i++) {
            $lines[] = '';
        }

        $padded = array_map(
            fn (string $line): string => $this->applyPadding($line, $paddingX, $context->bounds->width),
            $lines,
        );

        return $this->frame($context->bounds->width, $context->bounds->height, $padded);
    }

    private function align(string $text, int $width, string $align): string
    {
        $visible = TuiString::visibleLength($text);
        if ($visible >= $width) {
            return $text;
        }
        $gap = $width - $visible;

        return match ($align) {
            'center' => str_repeat(' ', (int) floor($gap / 2)) . $text . str_repeat(' ', (int) ceil($gap / 2)),
            'right' => str_repeat(' ', $gap) . $text,
            default => $text . str_repeat(' ', $gap),
        };
    }

    private function applyPadding(string $line, int $paddingX, int $width): string
    {
        if ($paddingX <= 0) {
            return TuiString::padEnd($line, $width);
        }
        $prefix = str_repeat(' ', $paddingX);
        $innerWidth = max(1, $width - ($paddingX * 2));
        $fitted = TuiString::truncate($line, $innerWidth);

        return $prefix . TuiString::padEnd($fitted, $innerWidth) . $prefix;
    }
}
