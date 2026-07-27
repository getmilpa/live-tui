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

use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The layout modes and the drawing options nothing had ever selected: the
 * horizontal allocator, overlays, alignment, padding and box chrome.
 *
 * A whole allocation strategy shipping unrun is the same defect as a renderer
 * shipping unrun — it just hides better, because the vertical one works.
 */
#[CoversClass(SimpleTuiLayoutEngine::class)]
#[CoversClass(TextRenderer::class)]
#[CoversClass(BoxRenderer::class)]
final class LayoutModesTest extends TestCase
{
    // ---- horizontal allocation ---------------------------------------------

    public function testHorizontalLayoutPlacesChildrenSideBySide(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal'], children: [
                new TuiNode('izq', 'text', props: ['text' => 'a']),
                new TuiNode('der', 'text', props: ['text' => 'b']),
            ]),
            new TuiBounds(0, 0, 40, 6),
        );

        $izq = $frame->boundsFor('izq');
        $der = $frame->boundsFor('der');

        self::assertNotNull($izq);
        self::assertNotNull($der);
        self::assertGreaterThan($izq->x, $der->x, 'The second child starts to the right of the first.');
        self::assertSame($izq->y, $der->y, 'Side by side means the same row.');
    }

    public function testAFixedWidthIsHonouredAndTheRestFlexes(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal'], children: [
                new TuiNode('fijo', 'text', props: ['text' => 'a', 'width' => 10]),
                new TuiNode('flex', 'text', props: ['text' => 'b']),
            ]),
            new TuiBounds(0, 0, 40, 6),
        );

        self::assertSame(10, $frame->boundsFor('fijo')?->width);
        self::assertGreaterThan(10, $frame->boundsFor('flex')?->width);
    }

    public function testFlexWeightsSplitTheRemainderProportionally(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal'], children: [
                new TuiNode('uno', 'text', props: ['text' => 'a', 'flex' => 1]),
                new TuiNode('tres', 'text', props: ['text' => 'b', 'flex' => 3]),
            ]),
            new TuiBounds(0, 0, 40, 6),
        );

        $uno = $frame->boundsFor('uno');
        $tres = $frame->boundsFor('tres');

        self::assertNotNull($uno);
        self::assertNotNull($tres);
        self::assertGreaterThan($uno->width, $tres->width, 'A heavier flex gets more room.');
    }

    public function testTheLastFlexChildAbsorbsTheRoundingRemainder(): void
    {
        // Three equal flexes over a width that does not divide evenly: the row
        // still has to end exactly at the viewport edge, or a column of cells
        // never gets painted.
        $viewport = new TuiBounds(0, 0, 41, 4);
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal'], children: [
                new TuiNode('a', 'text', props: ['text' => '1']),
                new TuiNode('b', 'text', props: ['text' => '2']),
                new TuiNode('c', 'text', props: ['text' => '3']),
            ]),
            $viewport,
        );

        $ultimo = $frame->boundsFor('c');
        self::assertNotNull($ultimo);
        self::assertSame($viewport->width, $ultimo->x + $ultimo->width, 'The row must reach the edge.');
    }

    public function testAGapSeparatesHorizontalChildren(): void
    {
        $sin = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal'], children: [
                new TuiNode('a', 'text', props: ['text' => '1']),
                new TuiNode('b', 'text', props: ['text' => '2']),
            ]),
            new TuiBounds(0, 0, 40, 4),
        );

        $con = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'split', props: ['layout' => 'horizontal', 'gap' => 4], children: [
                new TuiNode('a', 'text', props: ['text' => '1']),
                new TuiNode('b', 'text', props: ['text' => '2']),
            ]),
            new TuiBounds(0, 0, 40, 4),
        );

        self::assertGreaterThan($sin->boundsFor('b')?->x ?? 0, $con->boundsFor('b')?->x ?? 0);
    }

    public function testAVerticalGapAlsoSeparates(): void
    {
        $con = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', props: ['gap' => 2], children: [
                new TuiNode('a', 'text', props: ['text' => '1']),
                new TuiNode('b', 'text', props: ['text' => '2']),
            ]),
            new TuiBounds(0, 0, 20, 12),
        );

        self::assertGreaterThan(1, $con->boundsFor('b')?->y ?? 0);
    }

    // ---- overlays ------------------------------------------------------------

    public function testAnOverlayIsCentredOverItsParentRatherThanStacked(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('fondo', 'text', props: ['text' => 'x']),
                new TuiNode('modal', 'box', props: ['overlay' => true, 'width' => 10, 'height' => 4]),
            ]),
            new TuiBounds(0, 0, 40, 12),
        );

        $modal = $frame->boundsFor('modal');

        self::assertNotNull($modal);
        self::assertSame(10, $modal->width);
        self::assertSame(4, $modal->height);
        self::assertGreaterThan(0, $modal->x, 'Centred, not flush left.');
        self::assertGreaterThan(0, $modal->y, 'Centred, not flush top.');
    }

    public function testAnOverlayCanBePlacedExplicitly(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('modal', 'box', props: ['overlay' => true, 'width' => 8, 'height' => 3, 'x' => 2, 'y' => 1]),
            ]),
            new TuiBounds(0, 0, 40, 12),
        );

        $modal = $frame->boundsFor('modal');

        self::assertNotNull($modal);
        self::assertSame(2, $modal->x);
        self::assertSame(1, $modal->y);
    }

    public function testAnOversizedOverlayIsClampedToTheViewport(): void
    {
        $viewport = new TuiBounds(0, 0, 20, 6);
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('modal', 'box', props: ['overlay' => true, 'width' => 999, 'height' => 999]),
            ]),
            $viewport,
        );

        $modal = $frame->boundsFor('modal');

        self::assertNotNull($modal);
        self::assertLessThanOrEqual($viewport->width, $modal->width);
        self::assertLessThanOrEqual($viewport->height, $modal->height);
    }

    public function testAnOverlayWithoutASizeStillGetsOne(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('modal', 'box', props: ['overlay' => true]),
            ]),
            new TuiBounds(0, 0, 80, 24),
        );

        $modal = $frame->boundsFor('modal');

        self::assertNotNull($modal);
        self::assertGreaterThan(0, $modal->width);
        self::assertGreaterThan(0, $modal->height);
    }

    // ---- TextRenderer alignment and padding ----------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function alignments(): array
    {
        return ['left' => ['left'], 'center' => ['center'], 'right' => ['right']];
    }

    #[DataProvider('alignments')]
    public function testEveryAlignmentFillsTheWidth(string $align): void
    {
        $frame = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => 'hola', 'align' => $align]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 3)),
        );

        foreach ($frame->lines as $line) {
            self::assertSame(20, TuiString::visibleLength($line));
        }
    }

    public function testAlignmentActuallyMovesTheText(): void
    {
        $draw = static fn (string $align): string => (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => 'hola', 'align' => $align]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 1)),
        )->lines[0];

        self::assertNotSame($draw('left'), $draw('center'));
        self::assertNotSame($draw('center'), $draw('right'));
        self::assertStringStartsWith('hola', $draw('left'));
        self::assertStringEndsWith('hola', rtrim($draw('right')));
    }

    public function testHorizontalPaddingIndentsBothSides(): void
    {
        $line = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => 'hola', 'paddingX' => 3]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 1)),
        )->lines[0];

        self::assertStringStartsWith('   ', $line);
        self::assertSame(20, TuiString::visibleLength($line));
    }

    public function testVerticalPaddingLeavesBlankRows(): void
    {
        $frame = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => 'hola', 'paddingY' => 1]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 4)),
        );

        self::assertSame('', trim($frame->lines[0]), 'The first row is padding.');
    }

    public function testPaddingNamesTheHorizontalInsetOnly(): void
    {
        // `padding` is the horizontal inset; the vertical one has its own prop.
        // Asserting the two move together would be inventing a contract.
        $frame = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => 'hola', 'padding' => 2]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 4)),
        );

        self::assertSame('hola', trim($frame->lines[0]), 'Still on the first row.');
        self::assertStringStartsWith('  ', $frame->lines[0], 'But inset from the left.');
    }

    public function testTextWrapsWhenAskedAndTruncatesWhenNot(): void
    {
        $largo = str_repeat('palabra ', 10);

        $conWrap = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => $largo, 'wrap' => true]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 6)),
        );
        $sinWrap = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => $largo, 'wrap' => false]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 6)),
        );

        self::assertNotSame(implode('', $conWrap->lines), implode('', $sinWrap->lines));
    }

    public function testContentIsAnAliasForText(): void
    {
        $frame = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['content' => 'ALIAS']),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 2)),
        );

        self::assertStringContainsString('ALIAS', implode("\n", $frame->lines));
    }

    public function testTextThatAlreadyFillsTheWidthIsLeftAlone(): void
    {
        $exacto = str_repeat('x', 20);
        $line = (new TextRenderer())->render(
            new TuiNode('t', 'text', props: ['text' => $exacto, 'align' => 'center']),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 1)),
        )->lines[0];

        self::assertSame($exacto, $line);
    }

    // ---- BoxRenderer chrome --------------------------------------------------

    public function testABorderedBoxDrawsItsFourCorners(): void
    {
        $frame = (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['border' => true]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 5)),
        );

        $out = implode("\n", $frame->lines);

        self::assertStringContainsString('─', $out);
        self::assertStringContainsString('│', $out);
    }

    public function testATitleIsDrawnIntoTheTopBorder(): void
    {
        $frame = (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['border' => true, 'title' => 'TITULO']),
            new TuiRenderContext(new TuiBounds(0, 0, 30, 5)),
        );

        self::assertStringContainsString('TITULO', implode("\n", $frame->lines));
    }

    public function testATitleTooLongForTheBoxDoesNotBreakTheBorder(): void
    {
        $frame = (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['border' => true, 'title' => str_repeat('T', 50)]),
            new TuiRenderContext(new TuiBounds(0, 0, 12, 4)),
        );

        foreach ($frame->lines as $line) {
            self::assertSame(12, TuiString::visibleLength($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
    }

    public function testAFocusedBoxIsDrawnDifferently(): void
    {
        $draw = static fn (bool $focused): string => implode("\n", (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['border' => true, 'focused' => $focused]),
            new TuiRenderContext(new TuiBounds(0, 0, 20, 5)),
        )->lines);

        self::assertNotSame($draw(false), $draw(true));
    }

    public function testABackgroundFillCoversEveryCell(): void
    {
        $frame = (new BoxRenderer())->render(
            new TuiNode('b', 'box', props: ['bg' => '#101010', 'border' => false]),
            new TuiRenderContext(new TuiBounds(0, 0, 16, 4)),
        );

        self::assertCount(4, $frame->lines);
        foreach ($frame->lines as $line) {
            self::assertSame(16, TuiString::visibleLength($line));
        }
    }
}
