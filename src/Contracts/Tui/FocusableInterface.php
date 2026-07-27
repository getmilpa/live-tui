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

namespace Milpa\Live\Contracts\Tui;

/**
 * A node renderer that paints a text caret (a single-cell cursor a
 * user can move inside a field) implements this contract so the TUI
 * loop can position the hardware terminal cursor at that cell for IME
 * candidate-window placement. The TUI analog of pi-tui's `Focusable`
 * interface.
 *
 * The renderer emits a zero-width marker ({@see CURSOR_MARKER}) inside
 * its rendered output at the column where the caret lives; the loop
 * scans the painted buffer for that marker and emits a cursor-position
 * escape (`\x1b[{row};{col}H`) pointing the hardware cursor there. This
 * keeps the "where is the caret" answer in the renderer (which owns the
 * layout) while keeping the "tell the terminal" answer in the loop
 * (which owns the output stream) — neither side has to mirror the
 * other's concerns.
 *
 * Node renderers that do NOT show a text caret (buttons, lists, badges,
 * spacers, …) do not implement this; their focus state is purely visual
 * (the `›` marker, the theme's `selected` role) and the hardware cursor
 * stays hidden while they are focused.
 */
interface FocusableInterface
{
    /**
     * The zero-width APC escape the renderer inserts in its painted
     * output exactly one cell before the caret. The loop scans for
     * this marker in the rendered lines to locate the caret.
     */
    public const CURSOR_MARKER = "\x1b]12;milpa-cursor\x1b\\";

    /**
     * Whether this renderer is currently painting a caret in `$output`.
     * Implementations should answer true only when their node is
     * focused and a text caret is actually drawn — not when the node
     * is merely present or selected. The loop uses this to skip the
     * marker scan when no caret exists, and to decide whether to show
     * or hide the hardware cursor.
     */
    public function hasCaret(string $output): bool;

    /**
     * Returns the `[row, col]` zero-indexed cell of the caret in
     * `$output`, or `null` if no marker is present. The loop adds the
     * node's layout bounds to these coordinates to get the absolute
     * terminal cell the hardware cursor should move to.
     *
     * @return array{int, int}|null
     */
    public function caretPosition(string $output): ?array;
}
