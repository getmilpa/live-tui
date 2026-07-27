<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Renders a pragmatic markdown subset to plain text lines, leaving ANSI
 * styling to the final `TuiAnsiPainter` pass. The TUI analog of pi-tui's
 * `Markdown`. Supports the constructs that matter in a terminal dashboard
 * or agent transcript: ATX headings (`#`, `##`, `###`), paragraphs with
 * word wrapping, unordered lists (`-`/`*`/`+`), ordered lists (`1.`),
 * blockquotes (`>`), thematic breaks (`---`), fenced code blocks
 * (` ``` `), and inline emphasis (`**bold**`, `*italic*`, `_italic_`,
 * `` `code` ``). HTML tags are stripped to their text content.
 *
 * Inline emphasis is rendered as ANSI SGR codes directly in the line so
 * the diff buffer carries the styling through without a second pass —
 * `TuiAnsiPainter` is glyph-only by design and would not know how to
 * paint a bold word. The renderer emits `\033[1m`/`\033[3m`/`\033[0m`
 * and trusts the theme's final reset to clean up.
 *
 * Node props (all optional):
 *  - `content`  string  The markdown source. Alias: `text`.
 *  - `padding`  int     Horizontal padding. Default: 0.
 *  - `paddingX` int     Override horizontal padding. Default: 0.
 *  - `paddingY` int     Vertical padding (top + bottom). Default: 0.
 *  - `wrap`     bool    Word-wrap paragraphs/list items. Default: true.
 *  - `scrollToBottom` bool Anchor overflowing content to the latest lines. Default: false.
 *  - `scrollFromBottom` int Lines to move back from the latest viewport. Default: 0.
 */
final class MarkdownRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `markdown` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'markdown';
    }

    /**
     * Parses the `text` prop and returns the rendered block lines.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $content = (string) ($node->props['content'] ?? $node->props['text'] ?? '');
        $paddingX = (int) ($node->props['paddingX'] ?? $node->props['padding'] ?? 0);
        $paddingY = (int) ($node->props['paddingY'] ?? 0);
        $wrap = (bool) ($node->props['wrap'] ?? true);
        $scrollToBottom = (bool) ($node->props['scrollToBottom'] ?? false);
        $scrollFromBottom = max(0, (int) ($node->props['scrollFromBottom'] ?? 0));
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        $innerWidth = max(1, $width - ($paddingX * 2));
        $blocks = $this->parse($content);
        $lines = [];
        foreach ($blocks as $block) {
            $lines = array_merge($lines, $this->renderBlock($block, $innerWidth, $wrap));
        }

        $padded = [];
        for ($i = 0; $i < $paddingY; $i++) {
            $padded[] = '';
        }
        foreach ($lines as $line) {
            $padded[] = $this->applyPadding($line, $paddingX, $width);
        }
        for ($i = 0; $i < $paddingY; $i++) {
            $padded[] = '';
        }

        if ($scrollToBottom && count($padded) > $height) {
            $maxOffset = count($padded) - $height;
            $offset = min($maxOffset, $scrollFromBottom);
            $start = max(0, count($padded) - $height - $offset);
            $padded = array_slice($padded, $start, $height);
        } else {
            $padded = array_slice($padded, 0, $height);
        }

        while (count($padded) < $height) {
            $padded[] = str_repeat(' ', $width);
        }

        return $this->frame($width, $height, $padded);
    }

    /**
     * @return array<int, array{type: string, level?: int, text?: string, items?: array<int, string>, ordered?: bool, lang?: string}>
     */
    private function parse(string $content): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => $m[2]];
                $i++;
                continue;
            }

            if (preg_match('/^```(.*)$/', trim($line), $m)) {
                $lang = trim($m[1]);
                $code = [];
                $i++;
                while ($i < $count && trim($lines[$i]) !== '```') {
                    $code[] = $lines[$i];
                    $i++;
                }
                if ($i < $count) {
                    $i++;
                }
                $blocks[] = ['type' => 'code', 'lang' => $lang, 'text' => implode("\n", $code)];
                continue;
            }

            if (preg_match('/^\s*([-*+])\s+(.+)$/', $line, $m)) {
                $items = [];
                while ($i < $count && preg_match('/^\s*([-*+])\s+(.+)$/', $lines[$i], $itemMatch)) {
                    $items[] = $itemMatch[2];
                    $i++;
                }
                $blocks[] = ['type' => 'list', 'ordered' => false, 'items' => $items];
                continue;
            }

            if (preg_match('/^\s*(\d+)\.\s+(.+)$/', $line, $m)) {
                $items = [];
                while ($i < $count && preg_match('/^\s*(\d+)\.\s+(.+)$/', $lines[$i], $itemMatch)) {
                    $items[] = $itemMatch[2];
                    $i++;
                }
                $blocks[] = ['type' => 'list', 'ordered' => true, 'items' => $items];
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $quote = [];
                while ($i < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $quoteMatch)) {
                    $quote[] = $quoteMatch[1];
                    $i++;
                }
                $blocks[] = ['type' => 'quote', 'text' => implode(' ', $quote)];
                continue;
            }

            if (preg_match('/^\s*([-*_])\1{2,}\s*$/', $line)) {
                $blocks[] = ['type' => 'hr'];
                $i++;
                continue;
            }

            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i])) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph)];
        }

        return $blocks;
    }

    private function startsBlock(string $line): bool
    {
        return preg_match('/^(#{1,6})\s+/', $line) === 1
            || preg_match('/^\s*```/', $line) === 1
            || preg_match('/^\s*([-*+])\s+/', $line) === 1
            || preg_match('/^\s*\d+\.\s+/', $line) === 1
            || preg_match('/^\s*>\s?/', $line) === 1
            || preg_match('/^\s*([-*_])\1{2,}\s*$/', $line) === 1;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<int, string>
     */
    private function renderBlock(array $block, int $width, bool $wrap): array
    {
        return match ($block['type']) {
            'heading' => $this->renderHeading((int) $block['level'], (string) $block['text'], $width),
            'paragraph' => $this->renderParagraph((string) $block['text'], $width, $wrap),
            'list' => $this->renderList($block['items'], (bool) $block['ordered'], $width, $wrap),
            'quote' => $this->renderQuote((string) $block['text'], $width, $wrap),
            'code' => $this->renderCode((string) $block['text'], (string) ($block['lang'] ?? ''), $width),
            'hr' => [str_repeat('─', $width)],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function renderHeading(int $level, string $text, int $width): array
    {
        $prefix = match ($level) {
            1 => '═ ',
            2 => '── ',
            3 => '· ',
            default => '',
        };
        $body = $this->inline($text);
        $line = $prefix . $body;
        if ($level <= 2) {
            $line = "\033[1m" . $line . "\033[0m";
        }

        return [TuiString::padEnd(TuiString::truncate($line, $width), $width)];
    }

    /**
     * @return list<string>
     */
    private function renderParagraph(string $text, int $width, bool $wrap): array
    {
        $body = $this->inline($text);
        if (!$wrap) {
            return [TuiString::padEnd($this->truncateAnsi($body, $width), $width)];
        }

        return $this->wrapAnsi($body, $width);
    }

    /**
     * @param array<int, string> $items
     *
     * @return list<string>
     */
    private function renderList(array $items, bool $ordered, int $width, bool $wrap): array
    {
        $lines = [];
        $index = 1;
        foreach ($items as $item) {
            $marker = $ordered ? sprintf('%d. ', $index++) : '• ';
            $body = $this->inline($item);
            $markerWidth = TuiString::visibleLength($marker);
            if ($wrap) {
                $wrapped = $this->wrapAnsi($body, $width - $markerWidth);
                $first = true;
                foreach ($wrapped as $wrappedLine) {
                    $line = ($first ? $marker : str_repeat(' ', $markerWidth)) . $wrappedLine;
                    $lines[] = TuiString::padEnd($this->truncateAnsi($line, $width), $width);
                    $first = false;
                }
            } else {
                $line = $marker . $body;
                $lines[] = TuiString::padEnd($this->truncateAnsi($line, $width), $width);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderQuote(string $text, int $width, bool $wrap): array
    {
        $body = $this->inline($text);
        $innerWidth = max(1, $width - 2);
        $wrapped = $wrap ? $this->wrapAnsi($body, $innerWidth) : [$body];
        $lines = [];
        foreach ($wrapped as $quoteLine) {
            $line = '▌ ' . $this->truncateAnsi($quoteLine, $innerWidth);
            $lines[] = TuiString::padEnd($line, $width);
        }

        return $lines;
    }

    /**
     * Word-wraps a line that may contain inline ANSI codes to a target
     * visible width. Wraps on spaces (preserving the codes attached to
     * each word) and hard-breaks overlong words. Each wrapped line is
     * terminated with a reset escape so styles never bleed across rows.
     *
     * @return array<int, string>
     */
    private function wrapAnsi(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }
        if (TuiString::visibleLength($text) <= $width) {
            return [$text];
        }
        $words = preg_split('/ +/', $text) ?: [$text];
        $lines = [];
        $current = '';
        $currentWidth = 0;
        foreach ($words as $word) {
            $wordWidth = TuiString::visibleLength($word);
            if ($wordWidth > $width) {
                if ($current !== '') {
                    $lines[] = $current . "\033[0m";
                    $current = '';
                    $currentWidth = 0;
                }
                $plainWord = TuiString::stripAnsi($word);
                while ($wordWidth > $width) {
                    $chunk = TuiString::slice($plainWord, $width);
                    $lines[] = $chunk . "\033[0m";
                    $plainWord = TuiString::sliceFrom($plainWord, $width);
                    $wordWidth = TuiString::visibleLength($plainWord);
                }
                $current = $plainWord;
                $currentWidth = $wordWidth;
                continue;
            }
            $candidateWidth = $currentWidth + ($current === '' ? 0 : 1) + $wordWidth;
            if ($candidateWidth > $width) {
                $lines[] = $current . "\033[0m";
                $current = $word;
                $currentWidth = $wordWidth;
            } else {
                $current .= ($current === '' ? '' : ' ') . $word;
                $currentWidth = $candidateWidth;
            }
        }
        if ($current !== '') {
            $lines[] = $current . "\033[0m";
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderCode(string $code, string $lang, int $width): array
    {
        $lines = $code === '' ? [] : explode("\n", $code);
        $out = [];
        if ($lang !== '') {
            $out[] = TuiString::padEnd(TuiString::truncate('[' . $lang . ']', $width), $width);
        }
        foreach ($lines as $codeLine) {
            $out[] = TuiString::padEnd(TuiString::truncate($codeLine, $width), $width);
        }

        return $out;
    }

    private function applyPadding(string $line, int $paddingX, int $width): string
    {
        if ($paddingX <= 0) {
            return TuiString::padEnd($line, $width);
        }
        $prefix = str_repeat(' ', $paddingX);
        $innerWidth = max(1, $width - ($paddingX * 2));
        $visible = TuiString::visibleLength($line);
        if ($visible > $innerWidth) {
            $truncated = $this->truncateAnsi($line, $innerWidth);
        } else {
            $truncated = $line . str_repeat(' ', $innerWidth - $visible);
        }

        return $prefix . $truncated . $prefix;
    }

    /**
     * Truncates a line that may contain inline ANSI codes to a target
     * visible width, preserving the codes. {@see TuiString::truncate()}
     * strips ANSI before truncating, which would discard emphasis —
     * this keeps the codes by truncating the visible run only and
     * appending a final reset so styles never bleed past the cut.
     */
    private function truncateAnsi(string $line, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $plain = TuiString::stripAnsi($line);
        if (TuiString::visibleLength($plain) <= $width) {
            return $line;
        }
        $kept = TuiString::slice($plain, $width);

        return $kept . "\033[0m";
    }

    private function inline(string $text): string
    {
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/', "\033[1m$1\033[0m", $text) ?? $text;
        $text = preg_replace('/__(.+?)__/', "\033[1m$1\033[0m", $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', "\033[3m$1\033[0m", $text) ?? $text;
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/', "\033[3m$1\033[0m", $text) ?? $text;
        $text = preg_replace('/`(.+?)`/', "\033[2m$1\033[0m", $text) ?? $text;

        return $text;
    }
}
