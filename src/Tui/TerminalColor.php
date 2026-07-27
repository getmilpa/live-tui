<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Terminal color detection. The PHP port of pi-tui's
 * `terminal-colors.ts`. Parses the responses a terminal sends back
 * to OSC 11 (query background color) and DECSET 997 (query color
 * scheme) so a TUI can auto-theme itself: pick a dark or light
 * {@see TerminalTheme} based on the actual background the terminal
 * is using, instead of guessing from a hardcoded default.
 *
 * The flow is: the app emits `\x1b]11;?\x1b\\` (OSC 11 query) and
 * optionally `\x1b[?997$n` (color scheme query); the terminal replies
 * asynchronously with the sequences these helpers recognize. The
 * loop's input path feeds those replies to {@see isOsc11BackgroundColorResponse()}
 * / {@see parseOsc11BackgroundColor()} /
 * {@see parseTerminalColorSchemeReport()} and the app swaps its
 * theme accordingly.
 *
 * Pure utility — no state, no I/O. The loop or a bootstrap harness
 * owns the timing of the queries; this class only knows how to
 * recognize and parse the replies.
 */
final class TerminalColor
{
    public const OSC11_QUERY = "\x1b]11;?\x1b\\";
    public const COLOR_SCHEME_QUERY = "\x1b[?997\$n";

    public const SCHEME_DARK = 'dark';
    public const SCHEME_LIGHT = 'light';

    /**
     * Whether this input is the terminal's OSC 11 reply reporting its background
     * colour, which arrives on the input stream and must not be treated as a key.
     */
    public static function isOsc11BackgroundColorResponse(string $data): bool
    {
        return preg_match('/^\x1b\]11;[^\x07\x1b]*(?:\x07|\x1b\\\\)$/u', $data) === 1;
    }

    /**
     * Parses an OSC 11 background-color reply into an `{r, g, b}` array
     * (each 0–255), or `null` when the reply is malformed or uses an
     * unsupported format. Accepts the three forms xterm/iTerm/Kitty
     * emit: `#RRGGBB`, `#RRRRGGGGBBBB` (16-bit per channel), and
     * `rgb:RR/GG/BB` (with optional `rgba:` prefix).
     *
     * @return array{r: int, g: int, b: int}|null
     */
    public static function parseOsc11BackgroundColor(string $data): ?array
    {
        if (!preg_match('/^\x1b\]11;([^\x07\x1b]*)(?:\x07|\x1b\\\\)$/u', $data, $m)) {
            return null;
        }
        $value = trim($m[1]);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '#')) {
            $hex = substr($value, 1);
            if (preg_match('/^[0-9a-f]{6}$/i', $hex)) {
                return self::hexToRgb($value);
            }
            if (preg_match('/^[0-9a-f]{12}$/i', $hex)) {
                $r = self::parseOscHexChannel(substr($hex, 0, 4));
                $g = self::parseOscHexChannel(substr($hex, 4, 4));
                $b = self::parseOscHexChannel(substr($hex, 8, 4));
                if ($r === null || $g === null || $b === null) {
                    return null;
                }

                return ['r' => $r, 'g' => $g, 'b' => $b];
            }

            return null;
        }

        $rgbValue = preg_replace('/^rgba?:/i', '', $value);
        if ($rgbValue === null) {
            return null;
        }
        $parts = explode('/', $rgbValue);
        if (count($parts) < 3) {
            return null;
        }
        $r = self::parseOscHexChannel($parts[0]);
        $g = self::parseOscHexChannel($parts[1]);
        $b = self::parseOscHexChannel($parts[2]);
        if ($r === null || $g === null || $b === null) {
            return null;
        }

        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    /**
     * Parses a DECSET 997 color-scheme report (`\x1b[?997;Nn`) into
     * {@see SCHEME_DARK} or {@see SCHEME_LIGHT}, or `null` when the
     * input is not a color-scheme report.
     */
    public static function parseTerminalColorSchemeReport(string $data): ?string
    {
        if (!preg_match('/^\x1b\[\?997;(1|2)n$/', $data, $m)) {
            return null;
        }

        return $m[1] === '2' ? self::SCHEME_LIGHT : self::SCHEME_DARK;
    }

    /**
     * Returns whether the given RGB background color is "light" by
     * computing perceived luminance — the same heuristic pi-tui uses
     * to pick a theme when only the background color is known (no
     * explicit DECSET 997 report). A background is light when its
     * relative luminance exceeds 0.5.
     */
    public static function isLightBackground(int $r, int $g, int $b): bool
    {
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luminance > 0.5;
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    private static function hexToRgb(string $hex): array
    {
        $normalized = str_starts_with($hex, '#') ? substr($hex, 1) : $hex;

        return [
            'r' => (int) hexdec(substr($normalized, 0, 2)),
            'g' => (int) hexdec(substr($normalized, 2, 2)),
            'b' => (int) hexdec(substr($normalized, 4, 2)),
        ];
    }

    private static function parseOscHexChannel(string $channel): ?int
    {
        if (!preg_match('/^[0-9a-f]+$/i', $channel)) {
            return null;
        }
        $max = 16 ** strlen($channel) - 1;
        if ($max <= 0) {
            return null;
        }
        return (int) round((hexdec($channel) / $max) * 255);
    }
}
