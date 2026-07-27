<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

/**
 * Word-boundary navigation helpers. The PHP port of pi-tui's
 * `word-navigation.ts`. Pure functions — no state, no mutation. A
 * `text-input` or `editor` key handler calls them to compute the new
 * cursor column after the user presses `Ctrl+Left` / `Ctrl+Right` /
 * `Alt+B` / `Alt+F`.
 *
 * "Word" is defined the Emacs way: a run of word characters, OR a run
 * of punctuation, OR a run of whitespace. Each is one atomic jump.
 * Skipping trailing whitespace before the jump matches the Emacs
 * "skip-then-jump" feel: `foo |bar` (| = cursor) with `Alt+B` lands
 * at `foo| bar`, not at `|foo bar`.
 *
 * The implementation is Unicode-aware via {@see preg_match()} with the
 * `u` flag, so accented letters and CJK are treated as word characters
 * by `\w` (which is locale-independent in PCRE with `/u`). Punctuation
 * is the complement of `\w` and `\s` — the same tri-state split pi-tui
 * uses via `Intl.Segmenter`'s `isWordLike` flag.
 */
final class WordNavigation
{
    /**
     * Returns the cursor position after moving one word backward from
     * `$cursor` in `$text`. Skips trailing whitespace, then jumps one
     * word/punctuation boundary. Returns 0 when the cursor is already
     * at or before column 0.
     */
    public static function findWordBackward(string $text, int $cursor): int
    {
        if ($cursor <= 0) {
            return 0;
        }
        $before = mb_substr($text, 0, $cursor, 'UTF-8');
        $chars = mb_str_split($before, 1, 'UTF-8');
        $n = count($chars);
        $newCursor = $cursor;

        // Skip trailing whitespace.
        while ($n > 0 && self::isWhitespace($chars[$n - 1])) {
            $newCursor -= mb_strlen($chars[$n - 1], 'UTF-8');
            $n--;
        }
        if ($n === 0) {
            return $newCursor;
        }

        $class = self::charClass($chars[$n - 1]);
        // Skip one run of the same class (word, punctuation, …).
        while ($n > 0 && self::charClass($chars[$n - 1]) === $class && !self::isWhitespace($chars[$n - 1])) {
            $newCursor -= mb_strlen($chars[$n - 1], 'UTF-8');
            $n--;
        }

        return $newCursor;
    }

    /**
     * Returns the cursor position after moving one word forward from
     * `$cursor` in `$text`. Skips leading whitespace, then jumps one
     * word/punctuation boundary. Returns `mb_strlen($text)` when the
     * cursor is already at or past the end.
     */
    public static function findWordForward(string $text, int $cursor): int
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($cursor >= $len) {
            return $len;
        }
        $after = mb_substr($text, $cursor, null, 'UTF-8');
        $chars = mb_str_split($after, 1, 'UTF-8');
        $n = count($chars);
        $newCursor = $cursor;
        $i = 0;

        // Skip leading whitespace.
        while ($i < $n && self::isWhitespace($chars[$i])) {
            $newCursor += mb_strlen($chars[$i], 'UTF-8');
            $i++;
        }
        if ($i >= $n) {
            return $newCursor;
        }

        $class = self::charClass($chars[$i]);
        while ($i < $n && self::charClass($chars[$i]) === $class && !self::isWhitespace($chars[$i])) {
            $newCursor += mb_strlen($chars[$i], 'UTF-8');
            $i++;
        }

        return $newCursor;
    }

    private static function isWhitespace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }

    private static function charClass(string $char): string
    {
        if (preg_match('/^\w$/u', $char) === 1) {
            return 'word';
        }

        return 'punct';
    }
}
