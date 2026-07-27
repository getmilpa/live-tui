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

use Milpa\Live\Tui\BracketedPaste;
use Milpa\Live\Tui\Grapheme;
use Milpa\Live\Tui\InMemoryTuiEventBus;
use Milpa\Live\Tui\InputBuffer;
use Milpa\Live\Tui\NodeRenderers\MarkdownRenderer;
use Milpa\Live\Tui\TerminalTheme;
use Milpa\Live\Tui\TuiAnsiPainter;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Everything between raw bytes and painted cells: grapheme widths, escape
 * assembly, paste detection, markdown, and the ANSI painter.
 *
 * These are the classes a terminal exercises hardest and the suite exercised
 * least — every one of them was at zero.
 */
#[CoversClass(Grapheme::class)]
#[CoversClass(TuiString::class)]
#[CoversClass(InputBuffer::class)]
#[CoversClass(BracketedPaste::class)]
#[CoversClass(MarkdownRenderer::class)]
#[CoversClass(TuiAnsiPainter::class)]
#[CoversClass(TerminalTheme::class)]
#[CoversClass(InMemoryTuiEventBus::class)]
final class TextEngineTest extends TestCase
{
    // ---- Grapheme ------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function widths(): array
    {
        return [
            'ascii' => ['abc', 3],
            'empty' => ['', 0],
            'accented is one cell' => ['ñ', 1],
            'em dash is one cell' => ['—', 1],
            'box drawing is one cell' => ['│', 1],
            'ansi does not count' => ["\033[31mrojo\033[0m", 4],
            'mixed' => ['a—b', 3],
        ];
    }

    #[DataProvider('widths')]
    public function testItMeasuresInCells(string $text, int $expected): void
    {
        self::assertSame($expected, Grapheme::visibleWidth($text));
        self::assertSame($expected, TuiString::visibleLength($text));
    }

    public function testItSplitsIntoGraphemesNotBytes(): void
    {
        self::assertSame(['c', 'a', 'ñ', 'ó', 'n'], Grapheme::graphemes('cañón'));
        self::assertSame([], Grapheme::graphemes(''));
    }

    public function testASingleGraphemeReportsItsOwnWidth(): void
    {
        self::assertSame(1, Grapheme::graphemeWidth('a'));
        self::assertSame(1, Grapheme::graphemeWidth('ñ'));
        self::assertSame(0, Grapheme::graphemeWidth(''));
    }

    public function testTruncationNeverExceedsTheWidth(): void
    {
        foreach (['abcdefghij', 'cañón cañón', '——————————'] as $text) {
            foreach ([1, 3, 5, 8] as $width) {
                $cut = Grapheme::truncateToWidth($text, $width, '');
                self::assertLessThanOrEqual($width, Grapheme::visibleWidth($cut), "{$text} at {$width}");
            }
        }
    }

    public function testPaddingReachesExactlyTheWidth(): void
    {
        foreach (['a', 'ñ', '—', ''] as $text) {
            self::assertSame(9, Grapheme::visibleWidth(Grapheme::padEndToWidth($text, 9)));
        }
    }

    public function testTextThatAlreadyFitsIsNotCut(): void
    {
        self::assertSame('abc', TuiString::truncate('abc', 10));
    }

    public function testCleanRemovesControlCharactersButKeepsText(): void
    {
        self::assertStringContainsString('hola', TuiString::clean("ho\x00la"));
    }

    public function testStripAnsiLeavesOnlyTheText(): void
    {
        self::assertSame('rojo', TuiString::stripAnsi("\033[31mrojo\033[0m"));
    }

    public function testSliceTakesTheLeadingCells(): void
    {
        self::assertSame('ab', TuiString::slice('abcdef', 2));
        self::assertSame('ca', TuiString::slice('cañón', 2));
    }

    public function testSliceFromDropsTheLeadingCells(): void
    {
        self::assertSame('cdef', TuiString::sliceFrom('abcdef', 2));
    }

    public function testCellsAreOnePerColumn(): void
    {
        self::assertCount(5, TuiString::cells('cañón'));
    }

    public function testWordwrapBreaksOnTheWidth(): void
    {
        $wrapped = TuiString::wordwrap('hola mundo entero', 10);

        foreach (explode("\n", $wrapped) as $line) {
            self::assertLessThanOrEqual(10, TuiString::visibleLength($line));
        }
    }

    // ---- InputBuffer ---------------------------------------------------

    public function testAPrintableByteComesStraightBack(): void
    {
        self::assertSame('a', (new InputBuffer())->feed('a'));
    }

    public function testAnIncompleteSequenceIsHeldUntilItIsWhole(): void
    {
        $buffer = new InputBuffer();

        self::assertSame('', $buffer->feed("\033"));
        self::assertSame('', $buffer->feed('['));
        self::assertNotSame('', $buffer->pending());
        self::assertSame("\033[A", $buffer->feed('A'));
        self::assertSame('', $buffer->pending());
    }

    public function testFlushEmitsWhatIsHeld(): void
    {
        $buffer = new InputBuffer();
        $buffer->feed("\033");

        self::assertSame("\033", $buffer->flush());
        self::assertSame('', $buffer->flush(), 'A second flush has nothing left.');
    }

    public function testClearDropsWhatIsHeld(): void
    {
        $buffer = new InputBuffer();
        $buffer->feed("\033");
        $buffer->clear();

        self::assertSame('', $buffer->pending());
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function fragmentations(): array
    {
        return [
            'whole' => [["\033[A"]],
            'after esc' => [["\033", '[A']],
            'before final' => [["\033[", 'A']],
            'byte by byte' => [["\033", '[', 'A']],
        ];
    }

    #[DataProvider('fragmentations')]
    public function testEverySplitOfASequenceReassemblesToTheSameKey(array $fragments): void
    {
        $buffer = new InputBuffer();
        $out = '';
        foreach ($fragments as $fragment) {
            $out .= $buffer->feed($fragment);
        }

        self::assertSame("\033[A", $out);
    }

    public function testALongSequenceSurvivesFragmentation(): void
    {
        $long = "\033[200~hola\033[201~";
        $buffer = new InputBuffer();

        self::assertSame($long, $buffer->feed(substr($long, 0, 3)) . $buffer->feed(substr($long, 3)));
    }

    // ---- BracketedPaste ------------------------------------------------

    public function testTextOutsideAPasteFlowsThrough(): void
    {
        self::assertSame('hola', (new BracketedPaste())->feed('hola'));
    }

    public function testAPasteIsStrippedFromTheKeystrokeStream(): void
    {
        $paste = new BracketedPaste();

        self::assertSame('', $paste->feed("\033[200~pegado\033[201~"));
    }

    public function testAPasteIsPublishedAsOneEvent(): void
    {
        $bus = new InMemoryTuiEventBus();
        $seen = [];
        $bus->subscribe('paste.received', static function ($event) use (&$seen): void {
            $seen[] = $event->payload;
        });

        (new BracketedPaste($bus))->feed("\033[200~pegado\033[201~");

        self::assertCount(1, $seen);
        self::assertSame('pegado', $seen[0]['content']);
        self::assertSame(1, $seen[0]['lineCount']);
        self::assertFalse($seen[0]['collapsed']);
    }

    public function testAPasteSplitAcrossReadsStillArrivesWhole(): void
    {
        $bus = new InMemoryTuiEventBus();
        $seen = [];
        $bus->subscribe('paste.received', static function ($event) use (&$seen): void {
            $seen[] = $event->payload['content'];
        });

        $paste = new BracketedPaste($bus);
        $paste->feed("\033[200~pe");
        self::assertTrue($paste->isInPaste());
        self::assertGreaterThan(0, $paste->bufferedLength());
        $paste->feed("gado\033[201~");

        self::assertSame(['pegado'], $seen);
        self::assertFalse($paste->isInPaste());
    }

    public function testALongPasteIsCollapsedToAMarkerInsteadOfItsContent(): void
    {
        $bus = new InMemoryTuiEventBus();
        $seen = [];
        $bus->subscribe('paste.received', static function ($event) use (&$seen): void {
            $seen[] = $event->payload;
        });

        $lines = implode("\n", array_fill(0, 20, 'linea'));
        (new BracketedPaste($bus, collapse: true, collapseThreshold: 10))->feed("\033[200~{$lines}\033[201~");

        self::assertTrue($seen[0]['collapsed']);
        self::assertSame('', $seen[0]['content']);
        self::assertStringContainsString('20', (string) $seen[0]['marker']);
    }

    // ---- MarkdownRenderer ----------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markdown(): array
    {
        return [
            'heading' => ["# Titulo", 'Titulo'],
            'deep heading' => ["###### Hondo", 'Hondo'],
            'paragraph' => ['Un parrafo suelto.', 'parrafo'],
            'bullet list' => ["- uno\n- dos", 'uno'],
            'star list' => ["* uno\n* dos", 'dos'],
            'ordered list' => ["1. uno\n2. dos", 'uno'],
            'quote' => ['> citado', 'citado'],
            'rule' => ["---", '─'],
            'fenced code' => ["```php\necho 1;\n```", 'echo 1;'],
        ];
    }

    #[DataProvider('markdown')]
    public function testItRendersTheBlock(string $source, string $expected): void
    {
        $frame = (new MarkdownRenderer())->render(
            new TuiNode('md', 'markdown', props: ['content' => $source]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 8)),
        );

        self::assertStringContainsString($expected, implode("\n", $frame->lines));
    }

    public function testItAcceptsTextAsAnAliasForContent(): void
    {
        $frame = (new MarkdownRenderer())->render(
            new TuiNode('md', 'markdown', props: ['text' => '# Alias']),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );

        self::assertStringContainsString('Alias', implode("\n", $frame->lines));
    }

    public function testEmptyMarkdownStillFillsItsBounds(): void
    {
        $bounds = new TuiBounds(0, 0, 30, 5);
        $frame = (new MarkdownRenderer())->render(
            new TuiNode('md', 'markdown', props: ['content' => '']),
            new TuiRenderContext($bounds),
        );

        self::assertCount(5, $frame->lines);
        self::assertSame(30, $frame->width);
    }

    // ---- TuiAnsiPainter + TerminalTheme --------------------------------

    public function testPaintingJoinsTheLinesAndResetsAtTheEnd(): void
    {
        $out = (new TuiAnsiPainter())->paint(['uno', 'dos']);

        self::assertStringContainsString('uno', $out);
        self::assertStringContainsString('dos', $out);
        self::assertStringEndsWith("\033[0m", $out);
    }

    public function testPaintingNothingStillResets(): void
    {
        self::assertSame("\033[0m", (new TuiAnsiPainter())->paint([]));
    }

    public function testAThemeWithoutAnsiEmitsNoEscapes(): void
    {
        $plain = new TerminalTheme(ansi: false);

        self::assertSame('hola', $plain->style('hola', 'accent'));
        self::assertFalse($plain->ansiEnabled());
    }

    public function testAThemeWithAnsiWrapsTheText(): void
    {
        $themed = new TerminalTheme(ansi: true);
        $styled = $themed->style('hola', 'accent');

        self::assertTrue($themed->ansiEnabled());
        self::assertStringContainsString('hola', $styled);
        self::assertNotSame('hola', $styled);
    }

    public function testAThemeAnswersWithASymbolSoRenderersDoNotHardcodeOne(): void
    {
        self::assertNotSame('', (new TerminalTheme())->symbol('progress-fill'));
    }
}
