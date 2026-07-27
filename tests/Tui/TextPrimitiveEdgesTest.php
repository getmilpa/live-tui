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

use Milpa\Live\Tui\Grapheme;
use Milpa\Live\Tui\InputBuffer;
use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\Tui\TerminalTheme;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The primitives everything else is built on, at their edges: column-aware
 * slicing over multibyte text, the terminal sequences a person never types by
 * hand, the theme's whole vocabulary, and the table's empty and scrolled
 * states.
 */
#[CoversClass(TuiString::class)]
#[CoversClass(Grapheme::class)]
#[CoversClass(InputBuffer::class)]
#[CoversClass(TerminalTheme::class)]
#[CoversClass(DataTableRenderer::class)]
final class TextPrimitiveEdgesTest extends TestCase
{
    // ---- graphemes ------------------------------------------------------------

    public function testAnEmojiJoinedByZeroWidthJoinersIsOneGrapheme(): void
    {
        // Family emoji: several codepoints joined by zero-width joiners. Split
        // by codepoint it is five pieces and every column count downstream is
        // wrong — this is what the ICU segmenter is for.
        $graphemes = Grapheme::graphemes('a👩‍👧b');

        self::assertSame(['a', '👩‍👧', 'b'], $graphemes);
    }

    public function testAnEmptyStringHasNoGraphemes(): void
    {
        self::assertSame([], Grapheme::graphemes(''));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function graphemeWidths(): iterable
    {
        yield 'nothing' => ['', 0];
        yield 'a letter' => ['a', 1];
        yield 'an accented letter' => ['á', 1];
        yield 'a CJK ideograph' => ['漢', 2];
        yield 'a zero-width joiner' => ["\u{200D}", 0];
        yield 'a variation selector' => ["\u{FE0F}", 0];
        yield 'a combining mark' => ["\u{0301}", 0];
        yield 'a control character' => ["\x01", 0];
    }

    #[DataProvider('graphemeWidths')]
    public function testAGraphemeIsWorthTheColumnsItActuallyOccupies(string $grapheme, int $width): void
    {
        self::assertSame($width, Grapheme::graphemeWidth($grapheme));
    }

    public function testTruncatingWithAnEllipsisWiderThanTheRoomDropsTheEllipsis(): void
    {
        // No room for both: the text wins, since an ellipsis alone says nothing.
        $cut = Grapheme::truncateToWidth('漢字漢字', 2, '……');

        self::assertSame(2, Grapheme::visibleWidth($cut));
    }

    public function testPaddingSomethingAlreadyTooWideCutsItInstead(): void
    {
        self::assertSame(4, Grapheme::visibleWidth(Grapheme::padEndToWidth('漢字漢字', 4)));
    }

    public function testPaddingSomethingExactlyAsWideLeavesItAlone(): void
    {
        self::assertSame('漢字', Grapheme::padEndToWidth('漢字', 4));
    }

    // ---- TuiString -------------------------------------------------------------

    public function testTruncatingAsciiWithAnEllipsisWiderThanTheRoomDropsIt(): void
    {
        self::assertSame('ho', TuiString::truncate('hola mundo', 2, '...'));
    }

    public function testCellsFallsBackToBytesWhenTheTextIsNotValidUtf8(): void
    {
        // A lone continuation byte: the UTF-8 regex refuses the string outright,
        // and a renderer that returned nothing here would blank the cell.
        $cells = TuiString::cells("ho\xC3\x28la");

        self::assertNotSame([], $cells);
    }

    public function testSlicingFromTheStartOrBeforeItReturnsTheWholeText(): void
    {
        self::assertSame('hola', TuiString::sliceFrom('hola', 0));
        self::assertSame('hola', TuiString::sliceFrom('hola', -5));
    }

    public function testSlicingFromAnOffsetCountsColumnsNotBytes(): void
    {
        // Each ideograph is two columns wide, so column 4 is the third one.
        self::assertSame('漢字', TuiString::sliceFrom('漢字漢字', 4));
    }

    public function testSlicingIntoTheMiddleOfAWideCharacterKeepsIt(): void
    {
        // Column 3 lands inside the second ideograph. Dropping it would lose a
        // character; keeping it is the only choice that preserves the text.
        self::assertSame('字漢字', TuiString::sliceFrom('漢字漢字', 3));
    }

    public function testWrappingToNoWidthAtAllReturnsTheTextUntouched(): void
    {
        self::assertSame('hola mundo', TuiString::wordwrap('hola mundo', 0));
    }

    public function testAWordTooLongToFitIsBrokenAndWhatWasPendingIsFlushedFirst(): void
    {
        $wrapped = explode("\n", TuiString::wordwrap('ab ' . str_repeat('x', 12), 5));

        self::assertSame('ab', $wrapped[0], 'What was already on the line went out before the break.');
        self::assertSame('xxxxx', $wrapped[1]);
        self::assertSame('xxxxx', $wrapped[2]);
        self::assertSame('xx', $wrapped[3]);
    }

    // ---- the input buffer ---------------------------------------------------------

    public function testAnOscSequenceIsTakenWholeWhicheverTerminatorItUses(): void
    {
        $bel = new InputBuffer();
        $st = new InputBuffer();

        self::assertSame("\033]0;título\x07", $bel->feed("\033]0;título\x07"));
        self::assertSame("\033]0;título\033\\", $st->feed("\033]0;título\033\\"));
    }

    public function testAnOscCarryingBothTerminatorsLosesNoBytesEitherWay(): void
    {
        // feed() returns every complete sequence concatenated, so which of the
        // two terminators closed the OSC is not visible in its return value.
        // What is visible — and what an off-by-one in the cut would break — is
        // that the bytes come back whole and in order.
        $buffer = new InputBuffer();
        $input = "\033]0;a\x07b\033\\";

        self::assertSame($input, $buffer->feed($input));
        self::assertSame('', $buffer->pending());
    }

    public function testAnIncompleteOscIsHeldRatherThanEmittedInPieces(): void
    {
        $buffer = new InputBuffer();

        self::assertSame('', $buffer->feed("\033]0;sin termi"));
        self::assertNotSame('', $buffer->pending());
        self::assertSame("\033]0;sin terminar\x07", $buffer->feed("nar\x07"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringTerminated(): iterable
    {
        yield 'DCS' => ["\033P1\$r0m\033\\"];
        yield 'APC' => ["\033_G f=100\033\\"];
    }

    #[DataProvider('stringTerminated')]
    public function testAStringTerminatedSequenceIsTakenWhole(string $sequence): void
    {
        self::assertSame($sequence, (new InputBuffer())->feed($sequence));
    }

    #[DataProvider('stringTerminated')]
    public function testAnIncompleteStringTerminatedSequenceIsHeld(string $sequence): void
    {
        $buffer = new InputBuffer();

        self::assertSame('', $buffer->feed(substr($sequence, 0, -2)));
        self::assertSame($sequence, $buffer->feed("\033\\"));
    }

    // ---- the theme --------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        foreach (['title', 'muted', 'accent', 'error', 'success', 'warning', 'selected', 'oro', 'cal', 'tierra', 'input-surface'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('roles')]
    public function testEveryNamedRoleStylesTheTextItIsGiven(string $role): void
    {
        $styled = (new TerminalTheme(ansi: true))->style('hola', $role);

        self::assertStringContainsString('hola', $styled);
        self::assertStringContainsString("\033[", $styled, 'A named role always emits a code.');
    }

    public function testARoleNobodyDefinedLeavesTheTextAlone(): void
    {
        self::assertSame('hola', (new TerminalTheme(ansi: true))->style('hola', 'no-existe'), 'An unknown role adds nothing.');
        self::assertSame('hola', (new TerminalTheme())->style('hola', 'title'), 'And with ANSI off, no role adds anything.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symbols(): iterable
    {
        foreach (['selected', 'unselected', 'success', 'warning', 'error', 'info', 'trend-up', 'trend-down'] as $symbol) {
            yield $symbol => [$symbol];
        }
    }

    #[DataProvider('symbols')]
    public function testEverySymbolTheRenderersAskForHasAnAsciiStandIn(string $name): void
    {
        $symbol = (new TerminalTheme())->symbol($name);

        self::assertNotSame('', $symbol);
        self::assertSame(1, mb_strlen($symbol), 'One column, so a table built on it still lines up.');
    }

    // ---- the data table -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $props
     *
     * @return list<string>
     */
    private function table(array $props, int $width = 40, int $height = 8): array
    {
        return (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: $props),
            new TuiRenderContext(new TuiBounds(0, 0, $width, $height)),
        )->lines;
    }

    public function testATableWithNoRowsSaysSoAndStillFillsItsBox(): void
    {
        $lines = $this->table([
            'columns' => [['key' => 'nombre', 'label' => 'Nombre']],
            'rows' => [],
            'emptyText' => 'Nada que mostrar',
        ]);

        self::assertCount(8, $lines);
        self::assertStringContainsString('Nada que mostrar', implode("\n", $lines));
        foreach ($lines as $line) {
            self::assertSame(40, TuiString::visibleLength($line));
        }
    }

    public function testACaptionIsPaintedAboveTheHeaderAndMarkedWhenFocused(): void
    {
        $sinFoco = $this->table(['columns' => [['key' => 'a', 'label' => 'A']], 'rows' => [['a' => '1']], 'caption' => 'Clientes']);
        $conFoco = $this->table(['columns' => [['key' => 'a', 'label' => 'A']], 'rows' => [['a' => '1']], 'caption' => 'Clientes', 'focused' => true]);

        self::assertStringContainsString('Clientes', $sinFoco[0]);
        self::assertStringStartsWith('  ', $sinFoco[0]);
        self::assertStringStartsWith('›', $conFoco[0], 'Focus is marked on the caption itself.');
    }

    public function testAColumnWithADeclaredWidthKeepsItWhileTheRestFlex(): void
    {
        $lines = $this->table([
            'columns' => [
                ['key' => 'a', 'label' => 'Fija', 'width' => 6],
                ['key' => 'b', 'label' => 'Flexible'],
            ],
            'rows' => [['a' => 'xxxxxxxxxx', 'b' => 'y']],
        ]);

        // The fixed column is cut to its six columns; the flexible one takes
        // what is left instead of splitting the width evenly.
        self::assertStringContainsString('xxxx', $lines[2]);
        self::assertStringNotContainsString('xxxxxxxxxx', $lines[2]);
    }

    public function testTheBodyScrollsToKeepTheCursorInViewAndStopsAtTheLastPage(): void
    {
        $rows = array_map(static fn (int $i): array => ['id' => (string) $i, 'a' => 'fila' . $i], range(1, 40));
        $columns = [['key' => 'a', 'label' => 'A']];

        $arriba = implode("\n", $this->table(['columns' => $columns, 'rows' => $rows, 'cursor' => 0]));
        $enMedio = implode("\n", $this->table(['columns' => $columns, 'rows' => $rows, 'cursor' => 20]));
        $alFinal = implode("\n", $this->table(['columns' => $columns, 'rows' => $rows, 'cursor' => 39]));

        self::assertStringContainsString('fila1 ', $arriba);
        self::assertStringContainsString('fila20', $enMedio);
        self::assertStringNotContainsString('fila1 ', $enMedio, 'The window moved with the cursor.');
        self::assertStringContainsString('fila40', $alFinal);
    }

    public function testPerRowActionsArePaintedAfterTheLastColumn(): void
    {
        $lines = $this->table([
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rows' => [['id' => '1', 'a' => 'uno']],
            'actions' => [['key' => 'edit', 'label' => 'editar'], ['key' => 'ver', 'label' => 'ver']],
        ], width: 60);

        self::assertStringContainsString('editar ver', $lines[2]);
    }

    public function testASelectedRowIsCheckedAndTheCursorRowIsMarked(): void
    {
        $lines = $this->table([
            'columns' => [['key' => 'a', 'label' => 'A']],
            'rows' => [['id' => '1', 'a' => 'uno'], ['id' => '2', 'a' => 'dos']],
            'selected' => ['2'],
            'cursor' => 1,
            'focused' => true,
        ]);

        self::assertStringContainsString('✓', $lines[3]);
        self::assertStringStartsWith('›', $lines[3]);
    }
}
