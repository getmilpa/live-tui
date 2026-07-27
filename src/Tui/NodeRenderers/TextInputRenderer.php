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

use Milpa\Live\Contracts\Tui\FocusableInterface;
use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Single-line text input with horizontal scrolling and a fake cursor.
 * The TUI analog of pi-tui's `Input` component. This renderer is **pure**:
 * it paints whatever `value`/`cursor`/`placeholder` the node's props carry.
 * Owning that state across frames (and dispatching backspace, arrows,
 * letter keys to mutate them) is the caller's job — the `RetainedTuiLoop`
 * root factory, a live component, or whatever builds the `TuiNode` tree
 * each tick. That mirrors every other renderer in this namespace: pure
 * node-in/frame-out, no hidden state, no keyboard handler inside the
 * renderer.
 *
 * The cursor is a visible block cell so it survives retained composition
 * and plain output. A zero-width {@see FocusableInterface::CURSOR_MARKER}
 * remains immediately before it for hardware-cursor/IME positioning.
 *
 * Node props (all optional):
 *  - `value`       string Current text. Default: ''.
 *  - `cursor`      int    Cursor column (offset into value, in visible cells). Default: strlen(value).
 *  - `placeholder` string Shown when value is empty. Default: ''.
 *  - `secret`      bool   Mask each visible cell with `*`. Default: false.
 *  - `prompt`      string Prefix before the input (e.g. '> ' or '$ '). Default: ''.
 *  - `focused`     bool   Override focus state (otherwise read from context). Default: context.focused().
 */
final class TextInputRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface, FocusableInterface
{
    private const CURSOR_GLYPH = '█';

    /**
     * True only for `text-input` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'text-input';
    }

    /**
     * Draws the visible window of the value with the fake caret, scrolling
     * horizontally when the value is wider than the bounds.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $value = (string) ($node->props['value'] ?? '');
        $cursor = (int) ($node->props['cursor'] ?? TuiString::visibleLength($value));
        $placeholder = (string) ($node->props['placeholder'] ?? '');
        $secret = (bool) ($node->props['secret'] ?? false);
        $prompt = (string) ($node->props['prompt'] ?? '');
        $focused = (bool) ($node->props['focused'] ?? $context->focused($node));
        $width = $context->bounds->width;

        $promptWidth = TuiString::visibleLength($prompt);
        $fieldWidth = max(1, $width - $promptWidth);

        $window = $this->visibleWindow($value, $cursor, $fieldWidth, $secret);
        $visible = $window['text'];
        if ($value === '' && $placeholder !== '') {
            $visible = TuiString::truncate($placeholder, max(1, $fieldWidth - 2));
        }

        $field = $focused
            ? $this->paintCursor($visible, $window['cursor'], $fieldWidth, $value === '')
            : TuiString::padEnd($visible, $fieldWidth);
        $line = $prompt . $field;
        $line = TuiString::padEnd(TuiString::truncate($line, $width), $width);

        $rows = [];
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === 0 ? $line : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }

    /** @return array{text: string, cursor: int} */
    private function visibleWindow(string $value, int $cursor, int $fieldWidth, bool $secret): array
    {
        $cells = TuiString::cells($value);
        $total = count($cells);
        $cursor = max(0, min($total, $cursor));
        $start = 0;

        if ($total >= $fieldWidth) {
            $start = max(0, $cursor - (int) floor($fieldWidth / 2));
            $maxStart = max(0, $total - $fieldWidth + ($cursor === $total ? 1 : 0));
            $start = min($start, $maxStart);
            if ($cursor - $start >= $fieldWidth) {
                $start = $cursor - $fieldWidth + 1;
            }
        }

        $slice = implode('', array_slice($cells, $start, $fieldWidth));
        $text = $secret ? str_repeat('*', TuiString::visibleLength($slice)) : $slice;

        return [
            'text' => $text,
            'cursor' => max(0, min($fieldWidth - 1, $cursor - $start)),
        ];
    }

    private function paintCursor(string $visible, int $cursorColumn, int $fieldWidth, bool $empty): string
    {
        if ($empty) {
            $placeholder = $visible !== '' ? ' ' . $visible : '';

            return TuiString::padEnd(
                TuiString::truncate(FocusableInterface::CURSOR_MARKER . self::CURSOR_GLYPH . $placeholder, $fieldWidth, ''),
                $fieldWidth,
            );
        }

        $cells = TuiString::cells($visible);
        $cursorColumn = max(0, min($fieldWidth - 1, $cursorColumn));
        $before = implode('', array_slice($cells, 0, $cursorColumn));
        $after = $cursorColumn < count($cells)
            ? implode('', array_slice($cells, $cursorColumn + 1))
            : '';

        return TuiString::padEnd(
            TuiString::truncate($before . FocusableInterface::CURSOR_MARKER . self::CURSOR_GLYPH . $after, $fieldWidth, ''),
            $fieldWidth,
        );
    }

    /**
     * Whether the rendered output carries the caret marker, i.e. whether this
     * input is the node holding the caret.
     */
    public function hasCaret(string $output): bool
    {
        return str_contains($output, FocusableInterface::CURSOR_MARKER);
    }

    /**
     * The row and column of the caret within the rendered output, or null when
     * the output does not carry the marker.
     */
    public function caretPosition(string $output): ?array
    {
        $pos = strpos($output, FocusableInterface::CURSOR_MARKER);
        if ($pos === false) {
            return null;
        }
        $before = substr($output, 0, $pos);
        $col = TuiString::visibleLength($before);
        $row = substr_count($before, "\n");

        return [$row, $col];
    }

}
