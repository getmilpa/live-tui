<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

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
final class TextRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
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
     * Draws the wrapped, aligned and padded text within the bounds.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $content = (string) ($node->props['content'] ?? $node->props['text'] ?? '');
        $wrap = (bool) ($node->props['wrap'] ?? true);
        $align = (string) ($node->props['align'] ?? 'left');
        $paddingX = (int) ($node->props['paddingX'] ?? $node->props['padding'] ?? 0);
        $paddingY = (int) ($node->props['paddingY'] ?? 0);

        $innerWidth = max(1, $context->bounds->width - ($paddingX * 2));
        $text = $wrap && $content !== ''
            ? TuiString::wordwrap($content, $innerWidth)
            : $content;
        $rawLines = $content === '' ? [] : explode("\n", $text);

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
