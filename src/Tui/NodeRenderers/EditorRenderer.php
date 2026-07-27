<?php

declare(strict_types=1);

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\FocusableInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Multi-line text editor with vertical scrolling, word wrapping, and a
 * movable caret. The TUI analog of pi-tui's `Editor` component —
 * single-line input's big sibling. Pure renderer: the caller owns the
 * `text`, `cursor` `[row, col]`, `scrollOffset`, and (optionally) the
 * paste markers, and mutates them from a `RetainedTuiLoop` key handler
 * or a live component. This renderer only paints the snapshot.
 *
 * Layout:
 *   ┌─ Title ─────────────────────┐
 *   │ first line                   │
 *   │ sec|ond line                 │  <- caret between 'sec' and 'ond'
 *   │ third line                   │
 *   └──────────────────────────────┘
 *
 * The caret is painted with reverse-video and tagged with the
 * {@see FocusableInterface::CURSOR_MARKER} APC so the loop can position
 * the hardware terminal cursor at the caret cell for IME — the
 * multi-line analog of {@see TextInputRenderer}'s single-line caret.
 * When the cursor row is outside the visible scroll window, the
 * renderer auto-centers the scroll around it (the caller may override
 * via `scrollOffset`).
 *
 * Word-wrap is on by default; long lines wrap at the node width and the
 * cursor's `[row, col]` is interpreted in the *wrapped* coordinate
 * system so navigation stays consistent.
 *
 * Node props (all optional):
 *  - `text`     string  Multi-line content. Lines separated by "\n".
 *  - `cursor`   array  `[row, col]` zero-indexed in wrapped coordinates. Default: [0, 0].
 *  - `scrollOffset` int  First wrapped row to show. Default: auto-center on cursor.
 *  - `wrap`     bool   Word-wrap long lines. Default: true.
 *  - `title`    string  Title shown in the top border. Default: ''.
 *  - `border`   bool   Draw a border. Default: true.
 *  - `focused`  bool   Override focus. Default: context.focused().
 *  - `placeholder` string  Shown when text is empty. Default: ''.
 *  - `paddingX` int    Horizontal padding. Default: 0.
 *  - `showScrollInfo` bool  Show `row/total` in the bottom border. Default: true.
 */
final class EditorRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface, FocusableInterface
{
    /**
     * True only for `editor` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'editor';
    }

    /**
     * Draws the visible window of text with wrapping applied and the caret at
     * its current position.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $text = (string) ($node->props['text'] ?? '');
        $cursor = is_array($node->props['cursor'] ?? null) ? array_map('intval', $node->props['cursor']) : [0, 0];
        $cursorRow = $cursor[0] ?? 0;
        $cursorCol = $cursor[1] ?? 0;
        $wrap = (bool) ($node->props['wrap'] ?? true);
        $title = (string) ($node->props['title'] ?? '');
        $border = (bool) ($node->props['border'] ?? true);
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $placeholder = (string) ($node->props['placeholder'] ?? '');
        $paddingX = (int) ($node->props['paddingX'] ?? 0);
        $showScrollInfo = (bool) ($node->props['showScrollInfo'] ?? true);
        $width = $context->bounds->width;
        $height = $context->bounds->height;

        $borderH = $border ? 1 : 0;
        $innerW = max(1, $width - ($borderH * 2) - ($paddingX * 2));
        $innerH = max(1, $height - ($borderH * 2));
        $innerX = $borderH + $paddingX;
        $innerY = $borderH;

        $lines = $text === '' ? [] : explode("\n", $text);
        if ($text === '' && $placeholder !== '') {
            $lines = explode("\n", $placeholder);
        }

        $wrappedLines = [];
        $lineStarts = [];
        foreach ($lines as $i => $line) {
            $lineStarts[$i] = count($wrappedLines);
            if (!$wrap) {
                $wrappedLines[] = $line;
                continue;
            }
            $wrapped = TuiString::wordwrap($line === '' ? ' ' : $line, $innerW);
            foreach (explode("\n", $wrapped) as $wrappedLine) {
                $wrappedLines[] = $wrappedLine;
            }
        }
        $totalRows = count($wrappedLines);
        if ($totalRows === 0) {
            $wrappedLines = [''];
            $totalRows = 1;
        }

        $scrollOffset = isset($node->props['scrollOffset'])
            ? max(0, min(max(0, $totalRows - $innerH), (int) $node->props['scrollOffset']))
            : $this->autoScroll($cursorRow, $innerH, $totalRows);

        $rows = array_fill(0, $height, str_repeat(' ', $width));
        if ($border) {
            $rows = $this->paintBorder($rows, $width, $height, $title, $focused, $cursorRow, $totalRows, $showScrollInfo);
        }

        for ($i = 0; $i < $innerH; $i++) {
            $sourceRow = $scrollOffset + $i;
            if ($sourceRow >= $totalRows) {
                break;
            }
            $line = $wrappedLines[$sourceRow] ?? '';
            if ($focused && $sourceRow === $cursorRow) {
                $line = $this->paintCaret($line, $cursorCol, $innerW, $text === '' && $placeholder !== '');
            } else {
                $line = TuiString::truncate($line, $innerW);
            }
            $padded = $this->applyPadding($line, $paddingX, $width);
            $rows[$innerY + $i] = $padded;
        }

        return $this->frame($width, $height, $rows);
    }

    /**
     * Whether the rendered output carries the caret marker, i.e. whether this
     * editor is the node holding the caret.
     */
    public function hasCaret(string $output): bool
    {
        return str_contains($output, FocusableInterface::CURSOR_MARKER);
    }

    /**
     * The row and column of the caret within the rendered output, accounting for
     * wrapped lines, or null when the output does not carry the marker.
     */
    public function caretPosition(string $output): ?array
    {
        $pos = strpos($output, FocusableInterface::CURSOR_MARKER);
        if ($pos === false) {
            return null;
        }
        $before = substr($output, 0, $pos);
        $row = substr_count($before, "\n");
        $col = TuiString::visibleLength($before);

        return [$row, $col];
    }

    private function autoScroll(int $cursorRow, int $visible, int $total): int
    {
        if ($total <= $visible) {
            return 0;
        }
        $half = (int) floor($visible / 2);
        $offset = $cursorRow - $half;
        if ($offset < 0) {
            return 0;
        }
        if ($offset > $total - $visible) {
            return max(0, $total - $visible);
        }

        return $offset;
    }

    private function paintCaret(string $line, int $cursorCol, int $innerW, bool $placeholder): string
    {
        $cells = TuiString::cells($line);
        $col = max(0, min(count($cells) - 1, $cursorCol));
        $before = implode('', array_slice($cells, 0, $col));
        $at = $cells[$col] ?? ' ';
        $after = implode('', array_slice($cells, $col + 1));
        $filled = $before . FocusableInterface::CURSOR_MARKER . "\x1b[7m" . $at . "\x1b[0m" . $after;
        $filled = TuiString::truncate($filled, $innerW);
        if (TuiString::visibleLength($filled) < $innerW) {
            $filled .= str_repeat(' ', $innerW - TuiString::visibleLength($filled));
        }

        return $filled;
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
            $plainWord = TuiString::stripAnsi($line);
            $line = TuiString::slice($plainWord, $innerWidth) . "\033[0m";
        } else {
            $line .= str_repeat(' ', $innerWidth - $visible);
        }

        return $prefix . $line . $prefix;
    }

    /**
     * @param array<int, string> $rows
     *
     * @return array<int, string>
     */
    private function paintBorder(array $rows, int $width, int $height, string $title, bool $focused, int $cursorRow, int $totalRows, bool $showScrollInfo): array
    {
        if ($width < 2 || $height < 2) {
            return $rows;
        }
        $topLeft = $focused ? '╭' : '┌';
        $topRight = $focused ? '╮' : '┐';
        $botLeft = $focused ? '╰' : '└';
        $botRight = $focused ? '╯' : '┘';
        $marker = $focused ? '› ' : '  ';

        $titlePart = $title !== '' ? $marker . $title . ' ' : '';
        $topFill = max(0, $width - 2 - TuiString::visibleLength($titlePart));
        $rows[0] = TuiString::padEnd($topLeft . $titlePart . str_repeat('─', $topFill) . $topRight, $width);

        $scrollInfo = $showScrollInfo && $totalRows > 0 ? sprintf(' %d/%d ', $cursorRow + 1, $totalRows) : '';
        $botFill = max(0, $width - 2 - TuiString::visibleLength($scrollInfo));
        $rows[$height - 1] = TuiString::padEnd($botLeft . str_repeat('─', $botFill) . $scrollInfo . $botRight, $width);

        for ($y = 1; $y < $height - 1; $y++) {
            $cells = TuiString::cells($rows[$y]);
            $middle = array_fill(0, max(0, $width - 2), ' ');
            $rows[$y] = '│' . implode('', $middle) . '│';
        }

        return $rows;
    }
}
