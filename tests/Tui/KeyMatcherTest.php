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

namespace Milpa\Live\Tests\Tui;

use Milpa\Live\Tui\Key;
use Milpa\Live\Tui\KeyMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The table that turns terminal bytes into key names, exercised arm by arm.
 *
 * A lookup table is only as good as its least-used entry, and the entries are
 * what a keyboard produces: getting one wrong means one key silently does
 * nothing.
 */
#[CoversClass(KeyMatcher::class)]
#[CoversClass(Key::class)]
final class KeyMatcherTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sequences(): array
    {
        return [
            'arrow up' => ["\033[A", 'up'],
            'arrow down' => ["\033[B", 'down'],
            'arrow right' => ["\033[C", 'right'],
            'arrow left' => ["\033[D", 'left'],
            'home' => ["\033[H", 'home'],
            'end' => ["\033[F", 'end'],
            'page up' => ["\033[5~", 'pageup'],
            'page down' => ["\033[6~", 'pagedown'],
            'delete' => ["\033[3~", 'delete'],
            'shift tab' => ["\033[Z", 'shift+tab'],
            'bare escape' => ["\033", 'escape'],
            'double escape' => ["\033\033", 'escape'],
            'tab' => ["\t", 'tab'],
            'newline' => ["\n", 'enter'],
            'carriage return' => ["\r", 'enter'],
            'space' => [' ', 'space'],
            'del char' => ["\177", 'backspace'],
            'backspace char' => ["\010", 'backspace'],
            'ctrl+k' => ["\013", 'ctrl+k'],
            'ctrl+u' => ["\025", 'ctrl+u'],
            'ctrl+w' => ["\027", 'ctrl+w'],
            'ctrl+l' => ["\014", 'ctrl+l'],
            'ctrl+f' => ["\006", 'ctrl+f'],
            'ctrl+b' => ["\002", 'ctrl+b'],
            'ctrl+a' => ["\001", 'ctrl+a'],
            'ctrl+e' => ["\005", 'ctrl+e'],
            'ctrl+d' => ["\004", 'ctrl+d'],
            'ctrl+n' => ["\016", 'ctrl+n'],
            'ctrl+p' => ["\020", 'ctrl+p'],
            'ctrl+r' => ["\022", 'ctrl+r'],
            'ctrl+t' => ["\024", 'ctrl+t'],
            'ctrl+v' => ["\026", 'ctrl+v'],
            'ctrl+x' => ["\030", 'ctrl+x'],
            'ctrl+y' => ["\031", 'ctrl+y'],
            'ctrl+o' => ["\017", 'ctrl+o'],
            'ctrl+s' => ["\023", 'ctrl+s'],
            'ctrl+g' => ["\007", 'ctrl+g'],
            'alt+up' => ["\033\033[A", 'alt+up'],
            'alt+down' => ["\033\033[B", 'alt+down'],
            'alt+right' => ["\033\033[C", 'alt+right'],
            'alt+left' => ["\033\033[D", 'alt+left'],
            'alt+b' => ["\033b", 'alt+b'],
            'alt+f' => ["\033f", 'alt+f'],
        ];
    }

    #[DataProvider('sequences')]
    public function testItNamesTheSequence(string $raw, string $expected): void
    {
        self::assertSame($expected, KeyMatcher::normalize($raw));
    }

    #[DataProvider('sequences')]
    public function testTheNameMatchesItsOwnSequence(string $raw, string $expected): void
    {
        self::assertTrue(KeyMatcher::matches($raw, $expected));
    }

    public function testAnEmptyRawSequenceNamesNothing(): void
    {
        self::assertSame('', KeyMatcher::normalize(''));
    }

    public function testAPrintableCharacterIsItsOwnName(): void
    {
        self::assertSame('a', KeyMatcher::normalize('a'));
        self::assertSame('z', KeyMatcher::normalize('z'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function aliases(): array
    {
        return [
            'esc' => ['esc', 'escape'],
            'return' => ['return', 'enter'],
            'bksp' => ['bksp', 'backspace'],
            'bs' => ['bs', 'backspace'],
            'del' => ['del', 'delete'],
            'pgup' => ['pgup', 'pageup'],
            'pgdn' => ['pgdn', 'pagedown'],
            'ins' => ['ins', 'insert'],
            'uppercase' => ['ESC', 'escape'],
            'padded' => ['  esc  ', 'escape'],
        ];
    }

    #[DataProvider('aliases')]
    public function testAnAliasResolvesToItsCanonicalName(string $alias, string $canonical): void
    {
        self::assertSame($canonical, KeyMatcher::canonicalId($alias));
    }

    public function testModifiersAreOrderedSoTheSameChordHasOneName(): void
    {
        // Without a canonical order, ctrl+shift+p and shift+ctrl+p would be two
        // different bindings for one chord.
        self::assertSame('ctrl+shift+p', KeyMatcher::canonicalId('shift+ctrl+p'));
        self::assertSame('ctrl+shift+alt+k', KeyMatcher::canonicalId('alt+shift+ctrl+k'));
        self::assertSame('ctrl+meta+x', KeyMatcher::canonicalId('meta+ctrl+x'));
    }

    public function testAnUnknownModifierSortsLastRatherThanBeingDropped(): void
    {
        self::assertSame('ctrl+hyper+p', KeyMatcher::canonicalId('hyper+ctrl+p'));
    }

    public function testKeyBuildsTheNamesMatcherExpects(): void
    {
        self::assertSame('ctrl+c', Key::ctrl('c'));
        self::assertSame('shift+tab', Key::shift('tab'));
        self::assertSame('alt+left', Key::alt('left'));
        self::assertSame('meta+p', Key::meta('p'));
        self::assertSame('ctrl+shift+p', Key::ctrlShift('p'));
        self::assertSame('ctrl+alt+d', Key::ctrlAlt('d'));
        self::assertSame('shift+alt+f', Key::shiftAlt('f'));
    }

    public function testKeyNamesRoundTripThroughCanonicalId(): void
    {
        foreach ([Key::ctrl('c'), Key::ctrlShift('p'), Key::shiftAlt('f')] as $name) {
            self::assertSame($name, KeyMatcher::canonicalId($name), "{$name} is not already canonical.");
        }
    }

    public function testAPrintableCharacterIsRecognisedAsPrintable(): void
    {
        self::assertSame('a', Key::printableCharacter('a'));
        self::assertNull(Key::printableCharacter("\033[A"));
        self::assertNull(Key::printableCharacter(''));
    }

    /**
     * Home and End arrive in more than one shape, and only one was in the table.
     *
     * `CSI H`/`CSI F` is what some terminals emit; tmux, xterm and most of the rest send the VT
     * variant `CSI 1~`/`CSI 4~`, and some send `CSI 7~`/`CSI 8~`. Missing those, normalize() handed
     * back the raw sequence and every screen asking for 'home' or 'end' simply did not react —
     * measured in greenhouse evidence/0168 against a real tmux, where a chat screen advertised both
     * keys in its footer and neither did anything.
     */
    #[DataProvider('lasFormasDeHomeYEnd')]
    public function testHomeAndEndAreRecognisedHoweverTheTerminalSendsThem(string $secuencia, string $esperado): void
    {
        self::assertSame($esperado, KeyMatcher::normalize($secuencia));
    }

    /** @return array<string, array{string, string}> */
    public static function lasFormasDeHomeYEnd(): array
    {
        return [
            'home CSI H' => ["\033[H", 'home'],
            'home VT 1~ (lo que manda tmux)' => ["\033[1~", 'home'],
            'home VT 7~' => ["\033[7~", 'home'],
            'home SS3' => ["\033OH", 'home'],
            'end CSI F' => ["\033[F", 'end'],
            'end VT 4~ (lo que manda tmux)' => ["\033[4~", 'end'],
            'end VT 8~' => ["\033[8~", 'end'],
            'end SS3' => ["\033OF", 'end'],
        ];
    }

    /**
     * THE CONTROL: a sequence nobody mapped still comes back raw.
     *
     * Without it, a normalize() that answered 'home' to everything would pass every case above.
     */
    public function testAnUnmappedSequenceIsStillReturnedRaw(): void
    {
        self::assertSame("\033[99~", KeyMatcher::normalize("\033[99~"));
    }
}
