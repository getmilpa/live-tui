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

namespace Milpa\Live\Tests\Tui\NodeRenderers;

use Milpa\Live\Tui\NodeRenderers\StatusBarRenderer;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The status bar is the one surface where truncation is a usability bug
 * rather than a cosmetic one: it carries the key hints, and losing the last
 * hint at 80 columns means losing the way out.
 */
#[CoversClass(StatusBarRenderer::class)]
final class StatusBarRendererTest extends TestCase
{
    public function testAFullHintRowSurvivesEightyColumns(): void
    {
        $frame = (new StatusBarRenderer())->render(
            new TuiNode('status', 'status-bar', props: [
                'height' => 1,
                'left' => 'milpa · customer-picker',
                'right' => 'enter pick · bksp remove · ^l clear · ^p · q',
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 80, 1)),
        );

        $line = $frame->lines[0];

        self::assertStringNotContainsString('…', $line);
        self::assertStringEndsWith('q', rtrim($line));
    }

    public function testTheIndicatorReplacesTheDefaultMark(): void
    {
        $frame = (new StatusBarRenderer())->render(
            new TuiNode('status', 'status-bar', props: [
                'indicator' => '◌',
                'left' => 'milpa agent',
                'right' => 'q',
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 1)),
        );

        self::assertStringStartsWith('◌ milpa agent', $frame->lines[0]);
    }

    public function testTheFrameMatchesTheBoundsItWasGiven(): void
    {
        $bounds = new TuiBounds(0, 0, 24, 1);

        $frame = (new StatusBarRenderer())->render(
            new TuiNode('status', 'status-bar', props: ['left' => 'milpa', 'right' => 'q']),
            new TuiRenderContext($bounds),
        );

        self::assertSame($bounds->width, $frame->width);
        self::assertCount($bounds->height, $frame->lines);
    }
}
