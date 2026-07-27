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

/**
 * Width-aware string helpers. Everything here measures in terminal CELLS, not
 * bytes or code points, and ignores ANSI sequences — a renderer that measured
 * in bytes would not produce a wrong string, it would corrupt the frame,
 * because the buffer composites at bounds without clamping.
 */
final class TuiString
{
    private const ANSI_PATTERN = "/\x1B\[[0-?]*[ -\/]*[@-~]/";
    private const OSC_PATTERN = "/\x1B\][^\x1B\\\\]*(?:\x1B\\\\|\x07)/";
    private const APC_PATTERN = "/\x1B_[^\x1B\\\\]*\x1B\\\\/";

    /**
     * Strips control characters that would corrupt the frame, leaving printable
     * text and ANSI styling intact.
     */
    public static function clean(string $text): string
    {
        return trim(preg_replace('/[ \t]+/', ' ', strip_tags($text)) ?? $text);
    }

    /**
     * The text with every ANSI escape sequence removed.
     */
    public static function stripAnsi(string $text): string
    {
        $text = preg_replace(self::OSC_PATTERN, '', $text) ?? $text;
        $text = preg_replace(self::APC_PATTERN, '', $text) ?? $text;

        return preg_replace(self::ANSI_PATTERN, '', $text) ?? $text;
    }

    /**
     * The width the text occupies in terminal cells, ignoring ANSI sequences and
     * counting wide graphemes as the columns they really take.
     */
    public static function visibleLength(string $text): int
    {
        $plain = self::stripAnsi($text);
        // Fast path: pure ASCII (no multibyte) counts by byte length, same
        // as visible columns — the overwhelmingly common case in TUI output.
        if ($plain === '' || preg_match('/^[\x00-\x7F]*$/', $plain) === 1) {
            return strlen($plain);
        }
        // Multibyte path: delegate to Grapheme for CJK/emoji-aware column
        // counting. mb_strlen would count codepoints, not columns, so a
        // CJK ideograph (2 cells) would report as 1 — wrong for layout.
        return Grapheme::visibleWidth($plain);
    }

    /**
     * The text cut to fit the width, ending in the ellipsis when something was
     * removed. Text that already fits is returned unchanged.
     */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }
        $plain = self::stripAnsi($text);
        // Fast path: pure ASCII text AND ASCII ellipsis — original byte-level logic.
        if (preg_match('/^[\x00-\x7F]*$/', $plain) === 1 && preg_match('/^[\x00-\x7F]*$/', $ellipsis) === 1) {
            if (strlen($plain) <= $width) {
                return $text;
            }
            $ellipsisWidth = strlen($ellipsis);
            if ($ellipsisWidth >= $width) {
                return self::slice($text, $width);
            }

            return self::slice($text, $width - $ellipsisWidth) . $ellipsis;
        }
        // Multibyte path: use Grapheme for column-aware truncation.
        // We do NOT want the trailing \033[0m Grapheme emits (TuiString's
        // contract is plain text, not styled output), so we strip it.
        $truncated = Grapheme::truncateToWidth($text, $width, $ellipsis);
        if (str_ends_with($truncated, "\033[0m")) {
            $truncated = substr($truncated, 0, -4);
        }

        return $truncated;
    }

    /**
     * The text padded with spaces to exactly the given cell width.
     */
    public static function padEnd(string $text, int $width): string
    {
        $visibleLength = self::visibleLength($text);
        if ($visibleLength > $width) {
            return self::truncate($text, $width, '');
        }

        return $text . str_repeat(' ', max(0, $width - $visibleLength));
    }

    /**
     * The text split into one entry per terminal cell.
     *
     * @return array<int, string>
     */
    public static function cells(string $text): array
    {
        $plain = self::stripAnsi($text);
        if ($plain === '') {
            return [];
        }

        if (preg_match_all('/./us', $plain, $matches) === false) {
            return str_split($plain);
        }

        return $matches[0];
    }

    /**
     * The leading portion of the text that fits the width, without an ellipsis.
     */
    public static function slice(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $plain = self::stripAnsi($text);
        // Fast path: pure ASCII — substr is correct.
        if (preg_match('/^[\x00-\x7F]*$/', $plain) === 1) {
            return substr($plain, 0, $width);
        }
        // Multibyte path: slice by visible columns, not codepoints.
        $graphemes = Grapheme::graphemes($plain);
        $out = '';
        $remaining = $width;
        foreach ($graphemes as $grapheme) {
            $gw = Grapheme::graphemeWidth($grapheme);
            if ($gw > $remaining) {
                break;
            }
            $out .= $grapheme;
            $remaining -= $gw;
        }

        return $out;
    }

    /**
     * The complement of {@see self::slice()}: everything from the given
     * visible-column offset onward, mb-aware.
     */
    public static function sliceFrom(string $text, int $start): string
    {
        $plain = self::stripAnsi($text);
        if ($start <= 0) {
            return $plain;
        }
        // Fast path: pure ASCII — substr from offset.
        if (preg_match('/^[\x00-\x7F]*$/', $plain) === 1) {
            return substr($plain, $start);
        }
        // Multibyte path: skip $start visible columns, return the rest.
        $graphemes = Grapheme::graphemes($plain);
        $out = '';
        $skipped = 0;
        $taking = false;
        foreach ($graphemes as $grapheme) {
            $gw = Grapheme::graphemeWidth($grapheme);
            if (!$taking) {
                $skipped += $gw;
                if ($skipped >= $start) {
                    $taking = true;
                    if ($skipped > $start) {
                        // The grapheme straddled the boundary — include it.
                        $out .= $grapheme;
                    }
                }
                continue;
            }
            $out .= $grapheme;
        }

        return $out;
    }

    /**
     * Mb-aware equivalent of PHP's `wordwrap($text, $width, $break, true)`
     * -- wraps on word boundaries and hard-breaks any single word wider
     * than $width, but measures/cuts by visible columns (via
     * {@see self::visibleLength()}/{@see self::slice()}) instead of bytes,
     * so multibyte content (accents, emoji) wraps at the same width a
     * plain-ASCII string of that length would.
     */
    public static function wordwrap(string $text, int $width, string $break = "\n"): string
    {
        if ($width <= 0) {
            return $text;
        }

        $lines = [];
        $current = '';

        foreach (preg_split('/ /u', $text) ?: [$text] as $word) {
            while (self::visibleLength($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = self::slice($word, $width);
                $word = self::sliceFrom($word, $width);
            }

            if ($current === '') {
                $current = $word;
                continue;
            }

            if (self::visibleLength($current . ' ' . $word) > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current .= ' ' . $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return implode($break, $lines);
    }
}
