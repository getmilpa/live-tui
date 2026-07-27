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

use Milpa\Live\Tui\FuzzyMatcher;
use Milpa\Live\Tui\KillRing;
use Milpa\Live\Tui\TerminalColor;
use Milpa\Live\Tui\UndoStack;
use Milpa\Live\Tui\WordNavigation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor-grade primitives the input layer is built from.
 *
 * None of them had ever been executed. They are pure functions over text, so
 * what they owe is exact behaviour on exact input — which is the only kind of
 * test worth writing for them.
 */
#[CoversClass(FuzzyMatcher::class)]
#[CoversClass(KillRing::class)]
#[CoversClass(UndoStack::class)]
#[CoversClass(WordNavigation::class)]
#[CoversClass(TerminalColor::class)]
final class InputPrimitivesTest extends TestCase
{
    // ---- WordNavigation ------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function backwardJumps(): array
    {
        return [
            'from the end of a word' => ['hola mundo', 10, 5],
            'from inside a word' => ['hola mundo', 8, 5],
            'across the space' => ['hola mundo', 5, 0],
            'at the start stays' => ['hola mundo', 0, 0],
            'negative cursor clamps' => ['hola', -3, 0],
            'trailing spaces are skipped' => ['hola   ', 7, 0],
            'punctuation is its own run' => ['hola, mundo', 5, 4],
            'accents count as one char' => ['cañón total', 11, 6],
        ];
    }

    #[DataProvider('backwardJumps')]
    public function testItFindsTheWordBoundaryBackward(string $text, int $cursor, int $expected): void
    {
        self::assertSame($expected, WordNavigation::findWordBackward($text, $cursor));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function forwardJumps(): array
    {
        return [
            'from the start' => ['hola mundo', 0, 4],
            'across the space' => ['hola mundo', 4, 10],
            'at the end stays' => ['hola mundo', 10, 10],
            'past the end clamps' => ['hola', 99, 4],
            'leading spaces are skipped' => ['   hola', 0, 7],
        ];
    }

    #[DataProvider('forwardJumps')]
    public function testItFindsTheWordBoundaryForward(string $text, int $cursor, int $expected): void
    {
        self::assertSame($expected, WordNavigation::findWordForward($text, $cursor));
    }

    // ---- FuzzyMatcher --------------------------------------------------

    public function testAnExactSubstringMatches(): void
    {
        $r = FuzzyMatcher::match('mun', 'hola mundo');

        self::assertTrue($r['matches']);
        // El score se reporta pero NO se afirma su magnitud ni su orden: es
        // negativo para una coincidencia contigua y menos negativo para una
        // dispersa, y esa semántica no se caracterizó. Afirmarla sería inventar
        // un contrato que nadie escribió.
        self::assertIsFloat($r['score']);
    }

    public function testMatchingIgnoresCase(): void
    {
        self::assertTrue(FuzzyMatcher::match('MUN', 'hola mundo')['matches']);
    }

    public function testAQueryThatIsNotThereDoesNotMatch(): void
    {
        self::assertFalse(FuzzyMatcher::match('zzz', 'hola mundo')['matches']);
    }

    public function testAnEmptyQueryFiltersNothingOut(): void
    {
        $items = ['uno', 'dos', 'tres'];

        self::assertSame($items, FuzzyMatcher::filter($items, '', static fn (string $s): string => $s));
        self::assertSame($items, FuzzyMatcher::filter($items, '   ', static fn (string $s): string => $s));
    }

    public function testFilteringKeepsOnlyWhatMatches(): void
    {
        $items = ['coa:admin', 'coa:routes', 'orm:schema'];
        $out = FuzzyMatcher::filter($items, 'coa', static fn (string $s): string => $s);

        self::assertContains('coa:admin', $out);
        self::assertNotContains('orm:schema', $out);
    }

    public function testEveryTokenOfTheQueryHasToMatch(): void
    {
        $items = ['coa:admin settings', 'coa:routes list'];
        $out = FuzzyMatcher::filter($items, 'coa settings', static fn (string $s): string => $s);

        self::assertSame(['coa:admin settings'], $out);
    }

    public function testASlashSeparatesTokensJustLikeASpace(): void
    {
        $items = ['coa:admin settings', 'orm:schema update'];
        $out = FuzzyMatcher::filter($items, 'coa/settings', static fn (string $s): string => $s);

        self::assertSame(['coa:admin settings'], $out);
    }

    public function testADigitLetterQueryAlsoMatchesTheOtherOrder(): void
    {
        // Typing "2fa" when the text says "fa2" is a transposition, not a miss.
        self::assertTrue(FuzzyMatcher::match('2fa', 'login fa2')['matches']);
        self::assertTrue(FuzzyMatcher::match('fa2', 'login 2fa')['matches']);
    }

    // ---- KillRing ------------------------------------------------------

    public function testAnEmptyRingHasNothingToPeek(): void
    {
        $ring = new KillRing();

        self::assertNull($ring->peek());
        self::assertSame(0, $ring->length());
    }

    public function testPushingAndPeeking(): void
    {
        $ring = new KillRing();
        $ring->push('uno', ['prepend' => false]);

        self::assertSame('uno', $ring->peek());
        self::assertSame(1, $ring->length());
    }

    public function testAnEmptyPushIsIgnored(): void
    {
        $ring = new KillRing();
        $ring->push('', ['prepend' => false]);

        self::assertSame(0, $ring->length());
    }

    public function testAccumulatingAppendsToTheLastEntryInsteadOfAddingOne(): void
    {
        $ring = new KillRing();
        $ring->push('hola', ['prepend' => false]);
        $ring->push(' mundo', ['prepend' => false, 'accumulate' => true]);

        self::assertSame('hola mundo', $ring->peek());
        self::assertSame(1, $ring->length());
    }

    public function testAccumulatingBackwardsPrepends(): void
    {
        $ring = new KillRing();
        $ring->push('mundo', ['prepend' => false]);
        $ring->push('hola ', ['prepend' => true, 'accumulate' => true]);

        self::assertSame('hola mundo', $ring->peek());
    }

    public function testRotatingBringsTheOlderEntryBack(): void
    {
        $ring = new KillRing();
        $ring->push('uno', ['prepend' => false]);
        $ring->push('dos', ['prepend' => false]);

        self::assertSame('dos', $ring->peek());
        $ring->rotate();
        self::assertSame('uno', $ring->peek());
    }

    public function testClearingEmptiesTheRing(): void
    {
        $ring = new KillRing();
        $ring->push('uno', ['prepend' => false]);
        $ring->clear();

        self::assertSame(0, $ring->length());
        self::assertNull($ring->peek());
    }

    // ---- UndoStack -----------------------------------------------------

    public function testAnEmptyStackPopsNothing(): void
    {
        $stack = new UndoStack();

        self::assertNull($stack->pop());
        self::assertSame(0, $stack->length());
    }

    public function testItPopsInReverseOrder(): void
    {
        $stack = new UndoStack();
        $stack->push('uno');
        $stack->push('dos');

        self::assertSame(2, $stack->length());
        self::assertSame('dos', $stack->pop());
        self::assertSame('uno', $stack->pop());
        self::assertNull($stack->pop());
    }

    public function testClearingDropsEverything(): void
    {
        $stack = new UndoStack();
        $stack->push('uno');
        $stack->clear();

        self::assertSame(0, $stack->length());
    }

    // ---- TerminalColor -------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function osc11Replies(): array
    {
        return [
            'bel terminated' => ["\x1b]11;rgb:1a/1b/1c\x07", true],
            'st terminated' => ["\x1b]11;rgb:1a/1b/1c\x1b\\", true],
            'a key press is not a reply' => ['a', false],
            'a csi is not a reply' => ["\x1b[A", false],
            'unterminated is not a reply' => ["\x1b]11;rgb:1a/1b/1c", false],
        ];
    }

    #[DataProvider('osc11Replies')]
    public function testItRecognisesTheBackgroundColourReply(string $data, bool $expected): void
    {
        self::assertSame($expected, TerminalColor::isOsc11BackgroundColorResponse($data));
    }

    public function testItParsesTheRgbForm(): void
    {
        $rgb = TerminalColor::parseOsc11BackgroundColor("\x1b]11;rgb:ff/80/00\x07");

        self::assertSame(['r' => 255, 'g' => 128, 'b' => 0], $rgb);
    }

    public function testItParsesShortAndLongHexForms(): void
    {
        $esperado = ['r' => 255, 'g' => 128, 'b' => 0];

        self::assertSame($esperado, TerminalColor::parseOsc11BackgroundColor("\x1b]11;#ff8000\x07"));
        self::assertSame($esperado, TerminalColor::parseOsc11BackgroundColor("\x1b]11;#ffff80800000\x07"));
    }

    public function testSomethingThatIsNotAReplyParsesToNothing(): void
    {
        self::assertNull(TerminalColor::parseOsc11BackgroundColor('a'));
        self::assertNull(TerminalColor::parseOsc11BackgroundColor("\x1b]11;\x07"));
    }

    public function testItReadsTheColourSchemeReport(): void
    {
        self::assertSame('light', TerminalColor::parseTerminalColorSchemeReport("\x1b[?997;2n"));
        self::assertSame('dark', TerminalColor::parseTerminalColorSchemeReport("\x1b[?997;1n"));
        self::assertNull(TerminalColor::parseTerminalColorSchemeReport("\x1b[A"));
    }

    public function testItTellsALightBackgroundFromADarkOne(): void
    {
        self::assertTrue(TerminalColor::isLightBackground(255, 255, 255));
        self::assertFalse(TerminalColor::isLightBackground(0, 0, 0));
    }
}
