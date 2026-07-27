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

use Milpa\Live\Tests\Fixtures\FakeTerminal;
use Milpa\Live\Tui\FocusManager;
use Milpa\Live\Tui\Grapheme;
use Milpa\Live\Tui\InputBuffer;
use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\MarkdownRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiFrameFactory;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\Tui\VirtualTerminalBuffer;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiEvent;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The edges: empty inputs, degenerate sizes, wrap-around, and the branches a
 * happy path never reaches.
 *
 * Most defects this package has shipped lived in exactly these corners — a
 * `false` that vanished, a byte eaten by a trim mask, a shape silently dropped.
 */
#[CoversClass(FocusManager::class)]
#[CoversClass(InputBuffer::class)]
#[CoversClass(TuiString::class)]
#[CoversClass(Grapheme::class)]
#[CoversClass(BoxRenderer::class)]
#[CoversClass(MarkdownRenderer::class)]
#[CoversClass(SimpleTuiLayoutEngine::class)]
#[CoversClass(RetainedTuiLoop::class)]
#[CoversClass(RetainedTuiRenderer::class)]
#[CoversClass(VirtualTerminalBuffer::class)]
#[CoversClass(TuiFrameFactory::class)]
#[CoversClass(TuiEvent::class)]
final class EdgesTest extends TestCase
{
    // ---- FocusManager -----------------------------------------------------

    public function testAnEmptyFocusOrderHasNoCurrentId(): void
    {
        $focus = new FocusManager([]);

        self::assertNull($focus->currentId());
        self::assertNull($focus->next());
        self::assertNull($focus->previous());
        self::assertSame([], $focus->ids());
    }

    public function testFocusStartsOnTheFirstIdAndCyclesBothWays(): void
    {
        $focus = new FocusManager(['a', 'b', 'c']);

        self::assertSame('a', $focus->currentId());
        self::assertSame(['a', 'b', 'c'], $focus->ids());

        self::assertSame('b', $focus->next());
        self::assertSame('c', $focus->next());
        self::assertSame('a', $focus->next(), 'Next wraps to the start.');
        self::assertSame('c', $focus->previous(), 'Previous wraps to the end.');
    }

    public function testFocusingAKnownIdMovesThereAndAnUnknownOneDoesNot(): void
    {
        $focus = new FocusManager(['a', 'b']);

        $focus->focus('b');
        self::assertSame('b', $focus->currentId());

        $focus->focus('no-existe');
        self::assertSame('b', $focus->currentId(), 'An unknown id must not move focus.');
    }

    // ---- InputBuffer edges -------------------------------------------------

    public function testFeedingNothingProducesNothing(): void
    {
        self::assertSame('', (new InputBuffer())->feed(''));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function completeSequences(): array
    {
        return [
            'CSI arrow' => ["\033[A"],
            'CSI with parameters' => ["\033[1;5A"],
            'CSI tilde' => ["\033[3~"],
            'SS3' => ["\033OP"],
            'meta letter' => ["\033b"],
            'plain letter' => ['a'],
            'control byte' => ["\003"],
        ];
    }

    #[DataProvider('completeSequences')]
    public function testACompleteSequenceComesBackWhole(string $sequence): void
    {
        self::assertSame($sequence, (new InputBuffer())->feed($sequence));
    }

    public function testSeveralKeysInOneChunkComeBackTogether(): void
    {
        self::assertSame('abc', (new InputBuffer())->feed('abc'));
    }

    public function testAKeyAfterAnIncompleteSequenceIsNotLost(): void
    {
        $buffer = new InputBuffer();
        $buffer->feed("\033[");

        self::assertSame("\033[A", $buffer->feed('A'));
        self::assertSame('b', $buffer->feed('b'));
    }

    // ---- TuiString / Grapheme edges ---------------------------------------

    public function testZeroAndNegativeWidthsProduceNothing(): void
    {
        self::assertSame('', TuiString::truncate('abc', 0));
        self::assertSame('', TuiString::slice('abc', 0));
        self::assertSame('', Grapheme::truncateToWidth('abc', 0, ''));
    }

    public function testPaddingSomethingAlreadyTooWideTruncatesInstead(): void
    {
        self::assertSame(3, TuiString::visibleLength(TuiString::padEnd('abcdef', 3)));
    }

    public function testEmptyTextSurvivesEveryHelper(): void
    {
        self::assertSame('', TuiString::clean(''));
        self::assertSame('', TuiString::stripAnsi(''));
        self::assertSame(0, TuiString::visibleLength(''));
        self::assertSame('', TuiString::truncate('', 5));
        self::assertSame([], TuiString::cells(''));
        self::assertSame('', TuiString::sliceFrom('', 3));
    }

    public function testSliceFromPastTheEndIsEmpty(): void
    {
        self::assertSame('', TuiString::sliceFrom('abc', 99));
    }

    public function testWordwrapLeavesShortTextAlone(): void
    {
        self::assertSame('hola', TuiString::wordwrap('hola', 40));
    }

    public function testWordwrapBreaksAWordLongerThanTheWidth(): void
    {
        $wrapped = TuiString::wordwrap(str_repeat('x', 30), 10);

        foreach (explode("\n", $wrapped) as $line) {
            self::assertLessThanOrEqual(10, TuiString::visibleLength($line));
        }
    }

    // ---- Frames and buffers ------------------------------------------------

    public function testAFrameIsNormalisedToItsDeclaredSize(): void
    {
        $frame = TuiFrameFactory::fromLines(10, 3, ['a']);

        self::assertCount(3, $frame->lines);
        foreach ($frame->lines as $line) {
            self::assertSame(10, TuiString::visibleLength($line));
        }
    }

    public function testWritingOutsideTheBufferDoesNotCorruptIt(): void
    {
        $buffer = new VirtualTerminalBuffer(10, 2);
        $buffer->writeFrame(new TuiBounds(0, 5, 4, 1), TuiFrameFactory::fromLines(4, 1, ['abcd']));

        self::assertCount(2, $buffer->lines(), 'The buffer keeps its own height.');
    }

    public function testABufferComparedWithItselfHasNoChanges(): void
    {
        $buffer = new VirtualTerminalBuffer(8, 2);

        self::assertTrue($buffer->diff($buffer)->isEmpty());
    }

    public function testADiffCanBeTurnedIntoAnAnsiPatch(): void
    {
        $previous = new VirtualTerminalBuffer(8, 2);
        $current = new VirtualTerminalBuffer(8, 2);
        $current->writeFrame(new TuiBounds(0, 0, 4, 1), TuiFrameFactory::fromLines(4, 1, ['abcd']));

        $patch = $current->diff($previous)->renderAnsiPatch();

        self::assertStringContainsString('abcd', $patch);
    }

    // ---- BoxRenderer -------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function boxes(): array
    {
        return [
            'plain' => [[]],
            'with title' => [['title' => 'Titulo']],
            'no border' => [['border' => false]],
            'padded' => [['padding' => 2]],
            'padded per axis' => [['paddingX' => 3, 'paddingY' => 1]],
            'filled' => [['bg' => '#202020']],
            'focused' => [['focused' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('boxes')]
    public function testABoxAlwaysFillsItsBounds(array $props): void
    {
        $bounds = new TuiBounds(0, 0, 24, 5);
        $frame = (new BoxRenderer())->render(new TuiNode('b', 'box', props: $props), new TuiRenderContext($bounds));

        self::assertCount(5, $frame->lines);
        foreach ($frame->lines as $line) {
            self::assertSame(24, TuiString::visibleLength($line));
        }
    }

    public function testABoxTooSmallForItsChromeStillProducesAFrame(): void
    {
        $frame = (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['title' => 'x', 'border' => true]),
            new TuiRenderContext(new TuiBounds(0, 0, 2, 1)),
        );

        self::assertCount(1, $frame->lines);
    }

    // ---- Markdown edges ----------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function awkwardMarkdown(): array
    {
        return [
            'only whitespace' => ["   \n  \n"],
            'unclosed fence' => ["```php\necho 1;"],
            'nested list markers' => ["  - uno\n    - dos"],
            'quote with no space' => ['>citado'],
            'heading with no text' => ['#'],
            'mixed blocks' => ["# T\n\ntexto\n\n- a\n\n> q\n\n---\n\n```\nx\n```"],
            'very long line' => [str_repeat('palabra ', 40)],
            'accents and dashes' => ['cañón — año'],
        ];
    }

    #[DataProvider('awkwardMarkdown')]
    public function testMarkdownAlwaysProducesAWellFormedFrame(string $source): void
    {
        $bounds = new TuiBounds(0, 0, 32, 10);
        $frame = (new MarkdownRenderer())->render(
            new TuiNode('md', 'markdown', props: ['content' => $source]),
            new TuiRenderContext($bounds),
        );

        self::assertCount(10, $frame->lines);
        foreach ($frame->lines as $row => $line) {
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "Row {$row} is broken UTF-8.");
            self::assertSame(32, TuiString::visibleLength($line), "Row {$row} is not the declared width.");
        }
    }

    // ---- Layout edges ------------------------------------------------------

    public function testALeafTreeLaysOutWithoutChildren(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('solo', 'text', props: ['text' => 'x']),
            new TuiBounds(0, 0, 20, 4),
        );

        self::assertNotNull($frame->boundsFor('solo'));
        self::assertContains('solo', $frame->paintOrder);
    }

    public function testManySiblingsAllGetBoundsInsideTheViewport(): void
    {
        $children = [];
        for ($i = 0; $i < 12; $i++) {
            $children[] = new TuiNode('n' . $i, 'text', props: ['text' => (string) $i]);
        }

        $viewport = new TuiBounds(0, 0, 30, 6);
        $frame = (new SimpleTuiLayoutEngine())->layout(new TuiNode('root', 'box', children: $children), $viewport);

        foreach ($frame->bounds as $id => $bounds) {
            self::assertLessThanOrEqual($viewport->width, $bounds->x + $bounds->width, "{$id} is too wide.");
            self::assertLessThanOrEqual($viewport->height, $bounds->y + $bounds->height, "{$id} is too tall.");
        }
    }

    public function testANodeCanDeclareItsOwnHeight(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('fijo', 'text', props: ['text' => 'x', 'height' => 2]),
                new TuiNode('resto', 'text', props: ['text' => 'y']),
            ]),
            new TuiBounds(0, 0, 20, 8),
        );

        self::assertNotNull($frame->boundsFor('fijo'));
        self::assertNotNull($frame->boundsFor('resto'));
    }

    // ---- Retained loop edges -----------------------------------------------

    private function loop(array $script, ?callable $handle = null, int $w = 30, int $h = 5): array
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $terminal = new FakeTerminal($script, $w, $h);

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            static fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => 'x']),
            ]),
            ['a'],
            'a',
            $w,
            $h,
            true,
            $handle,
        );

        return [$loop, $terminal];
    }

    public function testSessionValuesRoundTripThroughTheLoop(): void
    {
        [$loop] = $this->loop(['q']);

        self::assertNull($loop->value('nada', null));
        self::assertSame('por-defecto', $loop->value('nada', 'por-defecto'));

        $loop->set('clave', 'valor');
        self::assertSame('valor', $loop->value('clave', null));
    }

    public function testFocusMovesForwardAndBackThroughTheLoop(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());

        $loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $registry),
            static fn (): TuiNode => new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => 'x']),
                new TuiNode('b', 'text', props: ['text' => 'y']),
            ]),
            ['a', 'b'],
            'a',
            30,
            5,
        );

        self::assertSame('a', $loop->focusedId());
        $loop->focusNext();
        self::assertSame('b', $loop->focusedId());
        $loop->focusPrevious();
        self::assertSame('a', $loop->focusedId());
        $loop->focus('b');
        self::assertSame('b', $loop->focusedId());
    }

    public function testTabAndShiftTabMoveFocusWithoutAHandler(): void
    {
        [$loop] = $this->loop(['q']);

        self::assertTrue($loop->dispatchKey("\t"));
        self::assertTrue($loop->dispatchKey("\033[Z"));
    }

    public function testAnUnhandledKeyIsRecordedRatherThanIgnored(): void
    {
        [$loop] = $this->loop(['q'], static fn (string $key): bool => false);

        $loop->dispatchKey('z');

        self::assertStringContainsString('z', (string) $loop->value('status', ''));
    }

    public function testRenderScreenAlwaysProducesTheFullFrame(): void
    {
        [$loop] = $this->loop(['q']);
        $screen = $loop->renderScreen();

        self::assertNotSame('', $screen);
        self::assertSame($screen, $loop->renderScreen(), 'A full paint is not affected by the diff.');
    }

    public function testMaxTicksBoundsARunThatWouldOtherwiseSpin(): void
    {
        [$loop, $terminal] = $this->loop(['', '', '']);

        $loop->runOn($terminal, idleMicroseconds: 0, maxTicks: 3);

        self::assertContains('stop', $terminal->lifecycle);
    }

    // ---- TuiEvent -----------------------------------------------------------

    public function testAnEventCarriesItsTypePayloadAndSource(): void
    {
        $event = TuiEvent::now('key.pressed', ['key' => 'q'], source: 'test');

        self::assertSame('key.pressed', $event->type);
        self::assertSame('q', $event->payload['key']);
        self::assertSame('test', $event->source);
    }
}
