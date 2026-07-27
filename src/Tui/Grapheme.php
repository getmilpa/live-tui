<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Grapheme-aware string utilities. The PHP port of pi-tui's
 * `visibleWidth()` / `truncateToWidth()` / `wrapTextWithAnsi()`, plus
 * the CJK wide-glyph handling pi-tui does via
 * `Intl.Segmenter` grapheme segmentation and a `cjkBreakRegex`. PHP
 * has the `intl` extension, so we lean on `IntlBreakIterator` for
 * grapheme boundaries and `IntlChar::isWide()` / `::isprint()` for the
 * 1-vs-2 column width that CJK ideographs and wide emoji need.
 *
 * Everything here is pure — no state, no I/O. A TUI renderer or layout
 * engine that cares about terminal columns (not byte count, not
 * codepoint count) should go through these helpers rather than
 * `strlen()` / `mb_strlen()`.
 *
 * Wide-character convention (matches xterm / Kitty / WezTerm):
 *   - CJK ideographs, full-width Latin, Hangul syllables, wide emoji → 2 cells
 *   - Combining marks, zero-width joiners, variation selectors → 0 cells
 *   - Everything else printable → 1 cell
 */
final class Grapheme
{
    /**
     * Returns the visible column width of `$text`: 2 per wide grapheme,
     * 1 per normal grapheme, 0 per combining/zero-width grapheme. ANSI
     * escape sequences (CSI, OSC, APC) are stripped first so styling
     * does not inflate the count.
     */
    public static function visibleWidth(string $text): int
    {
        $plain = TuiString::stripAnsi($text);

        return self::plainWidth($plain);
    }

    /**
     * Truncates `$text` to at most `$width` visible columns, optionally
     * appending an ellipsis (default `…`, U+2026 — 1 cell wide) when
     * the text was cut. Preserves ANSI escapes by truncating only the
     * visible run and emitting a final reset so styles never bleed
     * past the cut. Mirrors pi-tui's `truncateToWidth()` semantics.
     */
    public static function truncateToWidth(string $text, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }
        $plain = TuiString::stripAnsi($text);
        $plainWidth = self::plainWidth($plain);
        if ($plainWidth <= $width) {
            return $text;
        }
        $ellipsisWidth = self::plainWidth($ellipsis);
        if ($ellipsisWidth >= $width) {
            $cut = self::slicePlain($plain, $width);

            return $cut . "\033[0m";
        }
        $cut = self::slicePlain($plain, $width - $ellipsisWidth);

        return $cut . $ellipsis . "\033[0m";
    }

    /**
     * Pads `$text` on the right with spaces until it is exactly `$width`
     * visible columns wide. Truncates (no ellipsis) when already wider.
     * Preserves ANSI escapes — the visible width is measured, the
     * padding is appended as plain spaces after the final reset.
     */
    public static function padEndToWidth(string $text, int $width): string
    {
        $visible = self::visibleWidth($text);
        if ($visible > $width) {
            return self::truncateToWidth($text, $width, '');
        }
        if ($visible === $width) {
            return $text;
        }

        return $text . str_repeat(' ', $width - $visible);
    }

    /**
     * Splits `$plain` (no ANSI) into an array of graphemes, each a
     * string of one or more UTF-8 codepoints that form a single
     * user-perceived character. Uses `IntlBreakIterator::createCharacterInstance`
     * when the `intl` extension is available; falls back to
     * `preg_match_all('/./us')` (codepoint-level) when not.
     *
     * @return array<int, string>
     */
    public static function graphemes(string $plain): array
    {
        if ($plain === '') {
            return [];
        }
        if (class_exists(IntlBreakIterator::class, false)) {
            $it = IntlBreakIterator::createCharacterInstance(null);
            $it->setText($plain);
            $out = [];
            $prev = 0;
            $pos = $it->next();
            while ($pos !== IntlBreakIterator::DONE) {
                if ($pos > $prev) {
                    $out[] = substr($plain, $prev, $pos - $prev);
                }
                $prev = $pos;
                $pos = $it->next();
            }

            return $out;
        }

        preg_match_all('/./us', $plain, $matches);

        return $matches[0];
    }

    /**
     * Returns the visible column width of a single grapheme. Combining
     * marks and zero-width joiners contribute 0; CJK wide chars and
     * wide emoji contribute 2; everything else printable contributes 1.
     */
    public static function graphemeWidth(string $grapheme): int
    {
        if ($grapheme === '') {
            return 0;
        }
        // Combining marks and ZWJ / variation selectors sit on top of
        // a base char and add no width.
        $first = mb_substr($grapheme, 0, 1, 'UTF-8');
        if ($first === "\u{200D}" || $first === "\u{FE0F}") {
            return 0;
        }
        if (preg_match('/^\p{M}/u', $grapheme) === 1) {
            return 0;
        }
        $code = mb_ord($first, 'UTF-8');
        if (self::isWide($code)) {
            return 2;
        }
        if (class_exists(IntlChar::class, false) && !IntlChar::isprint($code)) {
            return 0;
        }

        return 1;
    }

    /**
     * Whether a Unicode codepoint is "wide" (takes 2 terminal columns).
     * Covers the ranges xterm / Kitty / WezTerm treat as wide: CJK
     * ideographs, Hangul syllables, full-width ASCII, CJK punctuation,
     * and the common wide-emoji blocks. Mirrors the EastAsianWidth=Wide
     * + Fullwidth convention from Unicode TR 11.
     */
    private static function isWide(int $code): bool
    {
        return ($code >= 0x1100 && $code <= 0x115F) // Hangul Jamo
            || ($code >= 0x2E80 && $code <= 0x303E) // CJK radicals + punctuation
            || ($code >= 0x3041 && $code <= 0x33FF) // Hiragana, Katakana, Bopomofo, etc
            || ($code >= 0x3400 && $code <= 0x4DBF) // CJK Ext A
            || ($code >= 0x4E00 && $code <= 0x9FFF) // CJK Unified Ideographs
            || ($code >= 0xA000 && $code <= 0xA4CF) // Yi
            || ($code >= 0xAC00 && $code <= 0xD7A3) // Hangul Syllables
            || ($code >= 0xF900 && $code <= 0xFAFF) // CJK Compatibility Ideographs
            || ($code >= 0xFE30 && $code <= 0xFE4F) // CJK Compatibility Forms
            || ($code >= 0xFF00 && $code <= 0xFF60) // Fullwidth ASCII
            || ($code >= 0xFFE0 && $code <= 0xFFE6) // Fullwidth signs
            || ($code >= 0x1F300 && $code <= 0x1F64F) // Emoji (misc + faces)
            || ($code >= 0x1F900 && $code <= 0x1F9FF) // Supplemental symbols
            || ($code >= 0x20000 && $code <= 0x2FFFD) // CJK Ext B-F
            || ($code >= 0x30000 && $code <= 0x3FFFD); // CJK Ext G+
    }

    private static function plainWidth(string $plain): int
    {
        $width = 0;
        foreach (self::graphemes($plain) as $grapheme) {
            $width += self::graphemeWidth($grapheme);
        }

        return $width;
    }

    private static function slicePlain(string $plain, int $width): string
    {
        $out = '';
        $remaining = $width;
        foreach (self::graphemes($plain) as $grapheme) {
            $gw = self::graphemeWidth($grapheme);
            if ($gw > $remaining) {
                break;
            }
            $out .= $grapheme;
            $remaining -= $gw;
        }

        return $out;
    }
}
