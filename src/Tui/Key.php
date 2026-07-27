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
 * Builder of canonical key ids for use with {@see KeyMatcher::matches()}.
 * The PHP analog of pi-tui's `Key` helper. Each method returns a string
 * id in the exact format {@see KeyMatcher::canonicalId()} expects, so
 * callers never have to remember the modifier order or the alias names.
 *
 * Usage:
 *   KeyMatcher::matches($raw, Key::ctrl('c'))
 *   KeyMatcher::matches($raw, Key::shift('tab'))
 *   KeyMatcher::matches($raw, Key::alt('left'))
 *   KeyMatcher::matches($raw, Key::ctrlShift('p'))
 *
 * The bare special-key constants (`Key::ENTER`, `Key::ESCAPE`, …) are
 * the same strings pi-tui uses so behavior transfers across ports.
 */
final class Key
{
    public const ENTER = 'enter';
    public const ESCAPE = 'escape';
    public const TAB = 'tab';
    public const SPACE = 'space';
    public const BACKSPACE = 'backspace';
    public const DELETE = 'delete';
    public const INSERT = 'insert';
    public const HOME = 'home';
    public const END = 'end';
    public const PAGEUP = 'pageup';
    public const PAGEDOWN = 'pagedown';
    public const UP = 'up';
    public const DOWN = 'down';
    public const LEFT = 'left';
    public const RIGHT = 'right';

    /**
     * The character this key produces, or null when it is not a printable one.
     */
    public static function printableCharacter(string $key): ?string
    {
        if ($key === self::SPACE || $key === ' ') {
            return ' ';
        }

        return mb_strlen($key, 'UTF-8') === 1 && preg_match('/^\P{C}$/u', $key) === 1
            ? $key
            : null;
    }

    /**
     * The key name for this key held with Control.
     */
    public static function ctrl(string $key): string
    {
        return KeyMatcher::canonicalId('ctrl+' . $key);
    }

    /**
     * The key name for this key held with Shift.
     */
    public static function shift(string $key): string
    {
        return KeyMatcher::canonicalId('shift+' . $key);
    }

    /**
     * The key name for this key held with Alt.
     */
    public static function alt(string $key): string
    {
        return KeyMatcher::canonicalId('alt+' . $key);
    }

    /**
     * The key name for this key held with Meta.
     */
    public static function meta(string $key): string
    {
        return KeyMatcher::canonicalId('meta+' . $key);
    }

    /**
     * The key name for this key held with Control and Shift.
     */
    public static function ctrlShift(string $key): string
    {
        return KeyMatcher::canonicalId('ctrl+shift+' . $key);
    }

    /**
     * The key name for this key held with Control and Alt.
     */
    public static function ctrlAlt(string $key): string
    {
        return KeyMatcher::canonicalId('ctrl+alt+' . $key);
    }

    /**
     * The key name for this key held with Shift and Alt.
     */
    public static function shiftAlt(string $key): string
    {
        return KeyMatcher::canonicalId('shift+alt+' . $key);
    }
}
