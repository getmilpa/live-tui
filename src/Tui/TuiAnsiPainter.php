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

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\TerminalThemeInterface;

/**
 * Applies color as a final pass over already-composed retained-path
 * output lines by matching literal box-drawing/glyph characters and
 * resolving their color through an injected {@see TerminalThemeInterface}
 * instead of hardcoded ANSI sequences -- the one ANSI/theme contract both
 * TUI runtimes now share. The painter always needs an ansi-enabled theme
 * (its entire job is to add color), so the default theme it falls back to
 * forces `ansi: true` regardless of what the caller's own theme instance
 * (if any) is configured for elsewhere.
 */
final readonly class TuiAnsiPainter
{
    public function __construct(
        private TerminalThemeInterface $theme = new TerminalTheme(ansi: true),
    ) {
    }

    /**
     * Colors known box-drawing/glyph characters in `$lines` via the
     * injected theme and joins them with a trailing reset escape, so
     * output composed without any color awareness gets themed retroactively.
     * Text this painter does not recognize as a themed glyph passes through
     * unchanged.
     *
     * @param array<int, string> $lines Already-composed, unthemed output lines.
     */
    public function paint(array $lines): string
    {
        $painted = array_map(fn (string $line): string => $this->paintLine($line), $lines);

        return implode(PHP_EOL, $painted) . "\033[0m";
    }

    private function paintLine(string $line): string
    {
        // Keep TextInputRenderer ANSI-free while giving the editable row a
        // distinct surface after layout has established its exact bounds.
        if (preg_match('/^(│)(\s*>\s?[^│]*)(│)\s*$/u', $line, $input) === 1) {
            return $this->theme->style($input[1], 'muted')
                . $this->theme->style($input[2], 'input-surface')
                . $this->theme->style($input[3], 'muted')
                . "\033[0m";
        }

        // Resolve bracket badges before adding any ANSI. Otherwise the `[`
        // in an SGR escape can pair with a later `]` in user text and corrupt
        // both the escape and the visible content.
        $line = preg_replace_callback(
            '/(\[[^\]\n]{1,30}\])/u',
            function (array $m): string {
                $inner = substr($m[1], 1, -1);
                if (preg_match('/^[a-z][a-z0-9]{1,3}$/', $inner)) {
                    return $m[1];
                }

                $normalized = strtolower(trim($inner));
                $role = match (true) {
                    in_array($normalized, ['ready', 'done', 'passed', 'success'], true) => 'success',
                    in_array($normalized, ['thinking', 'running', 'pending', 'loading'], true) => 'warning',
                    in_array($normalized, ['failed', 'error'], true) => 'error',
                    preg_match('/^(claude|gpt|gemini)/', $normalized) === 1 => 'azul',
                    default => 'accent',
                };

                return $this->theme->style($m[1], $role);
            },
            $line,
        ) ?? $line;

        // Style only heading text. Their rule glyphs remain plain until the
        // border pass, avoiding nested styles that reset the title color.
        $line = preg_replace_callback(
            '/(^|│ )(──|═)\s+([^│\r\n]*?\S)(?=\s*│|\s*$)/u',
            fn (array $m): string => $m[1] . $m[2] . ' ' . $this->theme->style($m[3], 'title'),
            $line,
        ) ?? $line;

        // Brand marks remain semantic plain text until this final pass.
        $line = str_replace('milpa agent', $this->theme->style('milpa agent', 'brand'), $line);
        $line = str_replace('◆', $this->theme->style('◆', 'brand'), $line);

        // Box-drawing runs (borders, rules, separators).
        $line = preg_replace_callback(
            '/[┌┐└┘╭╮╰╯├┤─│═┄┅┈┉┊┋]+/u',
            fn (array $match): string => $this->theme->style($match[0], 'muted'),
            $line,
        ) ?? $line;

        // Accent markers.
        $line = str_replace('›', $this->theme->style('›', 'accent'), $line);
        $line = str_replace('⌘', $this->theme->style('⌘', 'muted'), $line);
        $line = str_replace('»', $this->theme->style('»', 'accent'), $line);
        $line = str_replace('▌', $this->theme->style('▌', 'accent'), $line);
        $line = str_replace('▣', $this->theme->style('▣', 'azul'), $line);
        $line = str_replace('•', $this->theme->style('•', 'accent'), $line);
        $line = str_replace('·', $this->theme->style('·', 'muted'), $line);

        // Status glyphs.
        $line = str_replace('●', $this->theme->style('●', 'success'), $line);
        $line = str_replace('◉', $this->theme->style('◉', 'success'), $line);
        $line = str_replace('✓', $this->theme->style('✓', 'success'), $line);
        $line = str_replace('✕', $this->theme->style('✕', 'error'), $line);
        $line = str_replace('◌', $this->theme->style('◌', 'muted'), $line);
        $line = preg_replace_callback(
            '/⚠️?/u',
            fn (array $m): string => $this->theme->style($m[0], 'warning'),
            $line,
        ) ?? $line;

        // Spinner frame glyphs (braille) — accent colored.
        $line = preg_replace_callback(
            '/([⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏])/u',
            fn (array $m): string => $this->theme->style($m[1], 'accent'),
            $line,
        ) ?? $line;

        // Progress bar fill.
        $line = preg_replace_callback(
            '/(█+)/u',
            fn (array $m): string => $this->theme->style($m[1], 'success'),
            $line,
        ) ?? $line;

        return $line . "\033[0m";
    }
}
