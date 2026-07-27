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
 * Keyboard input matching for TUI renderers and loops. The PHP analog of
 * pi-tui's `matchesKey()` + `Key` helper. Turns the raw byte sequences a
 * terminal emits (legacy ANSI escapes, single control bytes, printable
 * chars) into a stable key id like `'ctrl+c'`, `'shift+tab'`, `'up'`,
 * and lets callers compare them against human-readable identifiers
 * (`Key::ctrl('c')`, `'up'`, `'shift+tab'`). The id format is the same
 * pi-tui uses, so behavior transfers: modifiers are listed in a fixed
 * order (`ctrl+shift+alt+<key>`), bare letters are lowercased, and the
 * named special keys (`enter`, `escape`, `tab`, …) are the same strings.
 *
 * Usage:
 *   if (KeyMatcher::matches($raw, Key::ctrl('c'))) { ... }
 *   if (KeyMatcher::matches($raw, 'up')) { ... }
 *   if (KeyMatcher::matches($raw, Key::shift('tab'))) { ... }
 *
 * The matcher does NOT depend on the Kitty keyboard protocol — it
 * recognizes the legacy ANSI sequences every terminal emits by default,
 * which is what `RetainedTuiLoop::readKey()` already consumes. Adding
 * Kitty support later means extending {@see normalize()}, not the
 * callers.
 */
final class KeyMatcher
{
    /**
     * Whether `$raw` (the byte sequence read from the terminal) matches
     * the given key id. The id may use any of the formats produced by
     * {@see Key::*} or the bare string aliases (`'enter'`, `'ctrl+c'`).
     * Modifier order in `$id` is normalized before comparison so
     * `'ctrl+shift+p'` and `'shift+ctrl+p'` match the same input.
     */
    public static function matches(string $raw, string $id): bool
    {
        return self::normalize($raw) === self::canonicalId($id);
    }

    /**
     * Normalizes a raw byte sequence into the canonical key id. Returns
     * an empty string for empty input so callers can skip no-op reads.
     */
    public static function normalize(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        return match ($raw) {
            "\033[A" => 'up',
            "\033[B" => 'down',
            "\033[C" => 'right',
            "\033[D" => 'left',
            "\033[H" => 'home',
            "\033[F" => 'end',
            "\033[5~" => 'pageup',
            "\033[6~" => 'pagedown',
            "\033[3~" => 'delete',
            "\033[Z" => 'shift+tab',
            "\033", "\e", "\033\033" => 'escape',
            "\t" => 'tab',
            "\n", "\r" => 'enter',
            ' ' => 'space',
            "\177", "\010" => 'backspace',
            "\013" => 'ctrl+k',
            "\025" => 'ctrl+u',
            "\027" => 'ctrl+w',
            "\014" => 'ctrl+l',
            "\006" => 'ctrl+f',
            "\002" => 'ctrl+b',
            "\001" => 'ctrl+a',
            "\005" => 'ctrl+e',
            "\004" => 'ctrl+d',
            "\016" => 'ctrl+n',
            "\020" => 'ctrl+p',
            "\022" => 'ctrl+r',
            "\024" => 'ctrl+t',
            "\026" => 'ctrl+v',
            "\030" => 'ctrl+x',
            "\031" => 'ctrl+y',
            "\017" => 'ctrl+o',
            "\023" => 'ctrl+s',
            "\007" => 'ctrl+g',
            "\033\033[A" => 'alt+up',
            "\033\033[B" => 'alt+down',
            "\033\033[C" => 'alt+right',
            "\033\033[D" => 'alt+left',
            "\033b" => 'alt+b',
            "\033f" => 'alt+f',
            "\033d" => 'alt+d',
            default => self::normalizeDefault($raw),
        };
    }

    private static function normalizeDefault(string $raw): string
    {
        if (strlen($raw) === 1) {
            $ord = ord($raw);
            if ($ord < 32 && $raw !== "\t") {
                $letter = chr($ord + 96);
                if ($letter >= 'a' && $letter <= 'z') {
                    return 'ctrl+' . $letter;
                }
            }
            if ($ord >= 32 && $ord < 127) {
                return strtolower($raw);
            }
        }

        if (str_starts_with($raw, "\033") && strlen($raw) === 2) {
            return 'alt+' . strtolower(substr($raw, 1, 1));
        }

        return strtolower($raw);
    }

    /**
     * Canonicalizes a human-readable id so modifier order and aliases do
     * not fragment comparisons. `'esc'` becomes `'escape'`; `'return'`
     * becomes `'enter'`; modifiers are reordered to `ctrl+shift+alt+`.
     */
    public static function canonicalId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = match ($id) {
            'esc' => 'escape',
            'return' => 'enter',
            'bksp', 'bs' => 'backspace',
            'del' => 'delete',
            'pgup' => 'pageup',
            'pgdn', 'pagedown' => 'pagedown',
            'ins' => 'insert',
            default => $id,
        };

        if (!str_contains($id, '+')) {
            return $id;
        }
        $parts = explode('+', $id);
        $key = array_pop($parts);
        $mods = array_map('trim', $parts);
        $order = ['ctrl', 'shift', 'alt', 'meta'];
        usort($mods, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });

        return implode('+', $mods) . '+' . $key;
    }
}
