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

use Milpa\Live\Contracts\Tui\FocusableInterface;
use Milpa\Live\Tui\NodeRenderers\EditorRenderer;
use Milpa\Live\Tui\NodeRenderers\MarkdownRenderer;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two renderers with the most branches nothing had ever selected: the
 * markdown block/wrap machinery and the editor's caret, scroll and padding.
 *
 * Both are pure — a node and a bounds go in, lines come out — so every one of
 * these branches was always reachable from a test. None of them had been.
 */
#[CoversClass(MarkdownRenderer::class)]
#[CoversClass(EditorRenderer::class)]
final class MarkdownAndEditorTest extends TestCase
{
    /**
     * @param array<string, mixed> $props
     *
     * @return list<string>
     */
    private function markdown(array $props, int $width = 30, int $height = 12): array
    {
        return (new MarkdownRenderer())->render(
            new TuiNode('md', 'markdown', props: $props),
            new TuiRenderContext(new TuiBounds(0, 0, $width, $height)),
        )->lines;
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return list<string>
     */
    private function editor(array $props, int $width = 24, int $height = 6): array
    {
        return (new EditorRenderer())->render(
            new TuiNode('ed', 'editor', props: $props),
            new TuiRenderContext(new TuiBounds(0, 0, $width, $height)),
        )->lines;
    }

    /**
     * The frame without its escapes: the caret is painted mid-word (marker,
     * reverse-video, reset), so the word it sits on is not a contiguous string
     * in the raw output.
     */
    private function plain(string $output): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m|\e\]12;[^\a\e]*(\a|\e\\\\)?/', '', $output);
    }

    // ---- markdown blocks ------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headings(): iterable
    {
        yield 'level 1' => ['# Título', '═ '];
        yield 'level 2' => ['## Sección', '── '];
        yield 'level 3' => ['### Detalle', '· '];
    }

    #[DataProvider('headings')]
    public function testEachHeadingLevelGetsItsOwnPrefix(string $source, string $prefix): void
    {
        $lines = $this->markdown(['content' => $source]);

        self::assertStringContainsString($prefix, $lines[0]);
    }

    public function testWithWrappingOffAParagraphIsCutInsteadOfFlowing(): void
    {
        $largo = str_repeat('palabra ', 10);

        $conWrap = $this->markdown(['content' => $largo, 'wrap' => true]);
        $sinWrap = $this->markdown(['content' => $largo, 'wrap' => false]);

        self::assertNotSame('', trim($conWrap[1]), 'Wrapped, so a second line carries the rest.');
        self::assertSame('', trim($sinWrap[1]), 'Cut, so there is no second line.');
        self::assertSame(30, TuiString::visibleLength($sinWrap[0]));
    }

    public function testWithWrappingOffAListItemIsAlsoCut(): void
    {
        $lines = $this->markdown(['content' => '- ' . str_repeat('elemento ', 8), 'wrap' => false]);

        self::assertStringContainsString('•', $lines[0]);
        self::assertSame('', trim($lines[1]), 'One item, one line.');
    }

    public function testAnOrderedListNumbersItsItems(): void
    {
        $lines = $this->markdown(['content' => "1. uno\n2. dos"]);

        self::assertStringContainsString('1. uno', $lines[0]);
        self::assertStringContainsString('2. dos', $lines[1]);
    }

    public function testAWordTooLongForTheWidthIsBrokenRatherThanOverflowing(): void
    {
        // A single word cannot be wrapped on a space: the renderer has to
        // hard-break it, and every piece still has to fit the width.
        $lines = $this->markdown(['content' => 'corta ' . str_repeat('x', 75)], width: 20);

        foreach (array_slice($lines, 0, 5) as $line) {
            self::assertLessThanOrEqual(20, TuiString::visibleLength($line));
        }
        self::assertStringContainsString('corta', $lines[0]);
        self::assertStringContainsString('xxxx', $lines[1], 'The overlong word starts on its own line.');
    }

    public function testHorizontalPaddingInsetsEveryLineOnBothSides(): void
    {
        $lines = $this->markdown(['content' => 'hola', 'paddingX' => 3], width: 20);

        self::assertStringStartsWith('   ', $lines[0]);
        self::assertSame(20, TuiString::visibleLength($lines[0]));
    }

    public function testALineWiderThanThePaddedAreaIsCutToFitIt(): void
    {
        $lines = $this->markdown(['content' => str_repeat('x', 40), 'paddingX' => 4, 'wrap' => false], width: 20);

        self::assertSame(20, TuiString::visibleLength($lines[0]));
        self::assertStringStartsWith('    ', $lines[0]);
    }

    public function testVerticalPaddingPushesTheContentDownAndLeavesRoomBelow(): void
    {
        $lines = $this->markdown(['content' => 'hola', 'paddingY' => 2]);

        self::assertSame('', trim($lines[0]));
        self::assertSame('', trim($lines[1]));
        self::assertStringContainsString('hola', $lines[2]);
    }

    public function testAnchoringToTheBottomShowsTheLatestLinesInsteadOfTheFirst(): void
    {
        $content = implode("\n\n", array_map(static fn (int $i): string => 'linea' . $i, range(1, 20)));

        $desdeArriba = $this->markdown(['content' => $content], height: 5);
        $desdeAbajo = $this->markdown(['content' => $content, 'scrollToBottom' => true], height: 5);

        self::assertStringContainsString('linea1', $desdeArriba[0]);
        self::assertStringContainsString('linea20', $desdeAbajo[4]);
    }

    public function testScrollingBackFromTheBottomMovesTheWindowUp(): void
    {
        $content = implode("\n\n", array_map(static fn (int $i): string => 'linea' . $i, range(1, 20)));

        $abajo = $this->markdown(['content' => $content, 'scrollToBottom' => true], height: 5);
        $atras = $this->markdown(['content' => $content, 'scrollToBottom' => true, 'scrollFromBottom' => 3], height: 5);

        self::assertNotSame($abajo, $atras);
        self::assertStringNotContainsString('linea20', implode('', $atras), 'Moved back, so the last line is off-screen.');
    }

    public function testScrollingBackFurtherThanThereIsContentStopsAtTheTop(): void
    {
        $content = implode("\n\n", array_map(static fn (int $i): string => 'linea' . $i, range(1, 8)));

        $lines = $this->markdown(['content' => $content, 'scrollToBottom' => true, 'scrollFromBottom' => 999], height: 4);

        self::assertStringContainsString('linea1', $lines[0], 'It clamps at the first line instead of scrolling past it.');
    }

    // ---- the editor -------------------------------------------------------------

    public function testAFocusedEditorMarksWhereTheCaretIs(): void
    {
        $lines = $this->editor(['text' => "hola\nmundo", 'cursor' => [1, 2], 'focused' => true, 'border' => false]);
        $output = implode("\n", $lines);

        $renderer = new EditorRenderer();

        self::assertTrue($renderer->hasCaret($output));
        self::assertSame([1, 2], $renderer->caretPosition($output), 'Row 1, column 2 — where the caret was asked to be.');
    }

    public function testAnUnfocusedEditorCarriesNoCaretAtAll(): void
    {
        $renderer = new EditorRenderer();
        $output = implode("\n", $this->editor(['text' => 'hola', 'focused' => false, 'border' => false]));

        self::assertFalse($renderer->hasCaret($output));
        self::assertNull($renderer->caretPosition($output));
    }

    public function testACaretPastTheEndOfTheLineLandsOnTheLastCell(): void
    {
        $lines = $this->editor(['text' => 'hola', 'cursor' => [0, 99], 'focused' => true, 'border' => false]);
        $output = implode("\n", $lines);

        $position = (new EditorRenderer())->caretPosition($output);

        self::assertNotNull($position);
        self::assertLessThan(24, $position[1], 'Clamped into the line, not left past its end.');
    }

    public function testTheViewFollowsTheCaretDownAndStopsAtTheLastPage(): void
    {
        $texto = implode("\n", array_map(static fn (int $i): string => 'linea' . $i, range(1, 30)));

        $arriba = implode("\n", $this->editor(['text' => $texto, 'cursor' => [0, 0], 'focused' => true, 'border' => false]));
        $abajo = implode("\n", $this->editor(['text' => $texto, 'cursor' => [29, 0], 'focused' => true, 'border' => false]));

        self::assertStringContainsString('linea1 ', $this->plain($arriba));
        self::assertStringContainsString('linea30', $this->plain($abajo), 'The window followed the caret to the end.');
        self::assertStringNotContainsString('linea1 ', $this->plain($abajo), 'And left the top behind.');
    }

    public function testAnExplicitScrollOffsetBeyondTheEndIsClampedToTheLastPage(): void
    {
        $texto = implode("\n", array_map(static fn (int $i): string => 'linea' . $i, range(1, 30)));

        $lines = $this->editor(['text' => $texto, 'scrollOffset' => 999, 'border' => false]);

        self::assertStringContainsString('linea30', implode("\n", $lines));
    }

    public function testAnEmptyEditorShowsItsPlaceholderAndStillTakesTheCaret(): void
    {
        $lines = $this->editor(['text' => '', 'placeholder' => 'Escribe algo…', 'focused' => true, 'border' => false]);
        $output = implode("\n", $lines);

        self::assertStringContainsString('scribe algo', $this->plain($output));
        self::assertStringContainsString(FocusableInterface::CURSOR_MARKER, $output);
    }

    public function testEditorPaddingInsetsTheTextOnBothSides(): void
    {
        $lines = $this->editor(['text' => 'hola', 'paddingX' => 2, 'border' => false]);

        self::assertStringStartsWith('  ', $lines[0]);
        self::assertSame(24, TuiString::visibleLength($lines[0]));
    }

    public function testALineWiderThanTheEditorPaddingIsCutToFitIt(): void
    {
        $lines = $this->editor(['text' => str_repeat('x', 60), 'paddingX' => 3, 'wrap' => false, 'border' => false]);

        self::assertSame(24, TuiString::visibleLength($lines[0]));
        self::assertStringStartsWith('   ', $lines[0]);
    }

    public function testAnEditorTooSmallForABorderDrawsNoneRatherThanADegenerateOne(): void
    {
        $lines = (new EditorRenderer())->render(
            new TuiNode('ed', 'editor', props: ['text' => 'x', 'border' => true]),
            new TuiRenderContext(new TuiBounds(0, 0, 1, 1)),
        )->lines;

        self::assertCount(1, $lines);
        self::assertStringNotContainsString('┌', $lines[0]);
    }
}
