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
 * The one ANSI/theme contract both TUI runtimes (the retained tree and
 * {@see \Milpa\Live\Tui\TuiAnsiPainter}) share for turning semantic
 * roles/symbol names into terminal output, so color and glyph choices
 * live in one place instead of being hardcoded ANSI escapes scattered
 * across node renderers.
 */
interface TerminalThemeInterface
{
    /**
     * Whether this theme actually emits ANSI escape sequences.
     * Implementations that answer `false` MUST make {@see style()} return
     * `$text` unchanged, so callers never need to branch on this before
     * calling `style()`/`symbol()` — a disabled theme is a safe, styling-free
     * default, not a separate code path.
     */
    public function ansiEnabled(): bool;

    /**
     * Applies the color/style associated with a semantic role (e.g.
     * `'title'`, `'success'`, `'error'`, `'muted'`) to `$text`. An
     * unrecognized `$role`, or {@see ansiEnabled()} being `false`, MUST
     * return `$text` unchanged rather than throwing.
     */
    public function style(string $text, string $role): string;

    /**
     * Resolves a named glyph (e.g. `'selected'`, `'trend-up'`) to the
     * character(s) this theme displays for it. Implementations SHOULD
     * return a sensible ASCII fallback for an unrecognized `$name` rather
     * than throwing, since symbol names may grow over time.
     */
    public function symbol(string $name): string;
}
