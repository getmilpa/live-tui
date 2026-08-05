<?php

declare(strict_types=1);

namespace Milpa\Live\Tests\Tui\NodeRenderers;

use Milpa\Live\Contracts\Tui\MeasurableTuiNodeRendererInterface;
use Milpa\Live\Tui\NodeRenderers\MarkdownRenderer;
use Milpa\Live\Tui\NodeRenderers\TextInputRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A renderer that can say how tall it will be — and why the caller needs to ask.
 *
 * The vertical layout splits leftover rows evenly between children that declare no `height`. That is
 * right for a dashboard and wrong for a conversation: the more that has been said, the less of each
 * thing you see, exactly when there is most to read. A caller can only scroll if it knows how tall
 * each entry is, and the only honest source of that number is the renderer that will draw it.
 */
#[CoversClass(TextRenderer::class)]
#[CoversClass(MarkdownRenderer::class)]
#[CoversClass(TextInputRenderer::class)]
final class MeasurableRenderersTest extends TestCase
{
    private function context(int $width, int $height): TuiRenderContext
    {
        return new TuiRenderContext(new TuiBounds(0, 0, $width, $height));
    }

    /** @param list<string> $lines */
    private function visible(array $lines): array
    {
        $plain = array_map(static fn (string $l): string => rtrim((string) preg_replace('/\033\[[0-9;]*m/', '', $l)), $lines);

        return array_values(array_filter($plain, static fn (string $l): bool => $l !== ''));
    }

    /** The three the conversation uses answer; that is what lets a caller stop dividing and scroll. */
    public function testTheThreeRenderersAConversationUsesCanMeasureThemselves(): void
    {
        foreach ([new TextRenderer(), new MarkdownRenderer(), new TextInputRenderer()] as $renderer) {
            self::assertInstanceOf(MeasurableTuiNodeRendererInterface::class, $renderer);
        }
    }

    /**
     * THE MEASUREMENT GOES THROUGH THE SAME PATH THAT DRAWS — not a second wrap that agrees today.
     *
     * Counting the lines separately would match `render()` right up until someone edited one of the
     * two, and the disagreement would show as text quietly missing from a screen, which is the
     * hardest defect in a TUI to even notice.
     */
    public function testMeasuringTextAgreesWithWhatDrawingProduces(): void
    {
        $renderer = new TextRenderer();
        $node = new TuiNode('t', 'text', props: ['text' => str_repeat('palabra ', 20), 'wrap' => true]);

        $measured = $renderer->measureHeight($node, 40);
        $drawn = \count($this->visible($renderer->render($node, $this->context(40, 99))->lines));

        self::assertSame($measured, $drawn);
        self::assertGreaterThan(1, $measured, 'a paragraph at 40 columns does not fit on one row');
    }

    /** Narrower means taller, which is the whole reason the width has to be passed in. */
    public function testTheSameTextIsTallerWhenThereIsLessRoom(): void
    {
        $renderer = new TextRenderer();
        $node = new TuiNode('t', 'text', props: ['text' => str_repeat('palabra ', 20)]);

        self::assertGreaterThan($renderer->measureHeight($node, 80), $renderer->measureHeight($node, 20));
    }

    /**
     * MARKDOWN DOES NOT WRAP LIKE PLAIN TEXT, and counting it from the source would guess low
     * exactly where the content is richest: a heading gains its rule, a list gains bullets.
     */
    public function testMeasuringMarkdownAgreesWithWhatDrawingProduces(): void
    {
        $renderer = new MarkdownRenderer();
        $node = new TuiNode('m', 'markdown', props: [
            'content' => "### Un encabezado\n\n1. uno\n1. dos\n\n- viñeta",
            'wrap' => true,
        ]);

        $measured = $renderer->measureHeight($node, 50);
        $drawn = \count($this->visible($renderer->render($node, $this->context(50, 99))->lines));

        self::assertSame($measured, $drawn);
    }

    /** A single-line input is one row: the field only grows when it is told it may. */
    public function testAnInputThatIsNotMultilineIsAlwaysOneRow(): void
    {
        $renderer = new TextInputRenderer();
        $node = new TuiNode('i', 'text-input', props: ['value' => str_repeat('palabra ', 30), 'prompt' => '› ']);

        self::assertSame(1, $renderer->measureHeight($node, 40));
    }

    /**
     * IN MULTILINE IT GROWS WITH WHAT IS TYPED — and the cap is not cosmetic.
     *
     * Without it, pasting a paragraph would push everything else off the screen and the field would
     * eat the very conversation it is answering.
     */
    public function testAMultilineInputGrowsWithTheValueAndStopsAtItsCap(): void
    {
        $renderer = new TextInputRenderer();
        $short = new TuiNode('i', 'text-input', props: ['value' => 'hola', 'prompt' => '› ', 'multiline' => true]);
        $long = new TuiNode('i', 'text-input', props: [
            'value' => str_repeat('palabra ', 40),
            'prompt' => '› ',
            'multiline' => true,
            'maxLines' => 3,
        ]);

        self::assertSame(1, $renderer->measureHeight($short, 40));
        self::assertSame(3, $renderer->measureHeight($long, 40), 'it stops at maxLines, it does not keep growing');
    }

    /**
     * AND IT SHOWS THE TAIL, NOT THE HEAD — because that is where the cursor is.
     *
     * A field that shows what is no longer being written, and hides what is, is a field that does
     * not work for writing.
     */
    public function testAMultilineInputShowsTheEndOfWhatIsBeingTyped(): void
    {
        $renderer = new TextInputRenderer();
        $node = new TuiNode('i', 'text-input', props: [
            'value' => 'principio ' . str_repeat('relleno ', 12) . 'final',
            'prompt' => '› ',
            'multiline' => true,
            'focused' => true,
        ]);

        $rows = $this->visible($renderer->render($node, $this->context(30, 2))->lines);

        self::assertNotSame([], $rows);
        self::assertStringContainsString('final', implode(' ', $rows));
        self::assertStringNotContainsString('principio', implode(' ', $rows), 'the head scrolled off, not the tail');
    }

    /** With nothing typed it shows its placeholder, so the field reads as a field. */
    public function testAnEmptyMultilineInputShowsItsPlaceholder(): void
    {
        $renderer = new TextInputRenderer();
        $node = new TuiNode('i', 'text-input', props: [
            'value' => '',
            'prompt' => '› ',
            'placeholder' => 'escribe tu pregunta…',
            'multiline' => true,
        ]);

        self::assertStringContainsString(
            'escribe tu pregunta',
            implode(' ', $this->visible($renderer->render($node, $this->context(40, 2))->lines)),
        );
    }

    /** An empty node measures one row, never zero: a height of zero is a node nobody can see. */
    public function testAnEmptyValueStillOccupiesOneRow(): void
    {
        self::assertSame(1, (new TextInputRenderer())->measureHeight(
            new TuiNode('i', 'text-input', props: ['value' => '', 'multiline' => true]),
            40,
        ));
    }
}
