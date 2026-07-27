<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\TerminalThemeInterface;

/**
 * The default {@see TerminalThemeInterface}: a fixed role => SGR-code
 * mapping and a fixed symbol => glyph mapping, both overridable per
 * instance (symbols via `$symbols`; roles are not currently
 * caller-configurable). Ships with `ansi` disabled by default, so
 * constructing one with no arguments is always a safe, styling-free
 * theme.
 */
final readonly class TerminalTheme implements TerminalThemeInterface
{
    /**
     * @param bool                  $ansi    Whether to actually emit ANSI escapes; when `false`, {@see style()}
     *                                       always returns its input unchanged.
     * @param array<string, string> $symbols Overrides for specific symbol names, consulted before the built-in
     *                                       defaults in {@see symbol()}.
     */
    public function __construct(
        private bool $ansi = false,
        private array $symbols = [],
    ) {
    }

    public function ansiEnabled(): bool
    {
        return $this->ansi;
    }

    /**
     * The text wrapped in the ANSI styling this theme assigns to the semantic role.
     */
    public function style(string $text, string $role): string
    {
        if (!$this->ansi) {
            return $text;
        }

        // Milpa palette truecolor codes (from the brand spec):
        //   oro maíz #E8B14C, tierra #17120D, cal #ECE6D8,
        //   azul maíz #4B4794.
        $code = match ($role) {
            'title', 'accent' => '38;2;232;177;76',   // oro maíz
            'brand' => '1;38;2;232;177;76',            // oro maíz bold
            'success' => '38;2;138;201;99',            // verde milpa (calmer)
            'warning' => '38;2;232;177;76',            // oro
            'error' => '38;2;235;87;87',               // rojo calmer
            'muted' => '38;2;154;144;120',             // cal muted
            'selected' => '7',
            'oro' => '38;2;232;177;76',
            'azul' => '38;2;75;71;148',
            'cal' => '38;2;236;230;216',
            'tierra' => '38;2;23;18;13',
            'input-surface' => '38;2;236;230;216;48;2;35;32;58',
            default => '',
        };

        if ($code === '') {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m";
    }

    /**
     * The glyph this theme uses for the named symbol, so a renderer never hardcodes
     * one.
     */
    public function symbol(string $name): string
    {
        return $this->symbols[$name] ?? match ($name) {
            'selected' => 'x',
            'unselected' => ' ',
            'success' => '+',
            'warning' => '!',
            'error' => 'x',
            'info' => 'i',
            'trend-up' => '^',
            'trend-down' => 'v',
            default => '-',
        };
    }
}
