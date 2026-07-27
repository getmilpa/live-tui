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

use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `columns` is the prop most likely to be passed wrong, because a list of
 * plain strings looks like the obvious shape and fails silently — the frame
 * comes back blank instead of throwing. These tests pin the real contract.
 */
#[CoversClass(DataTableRenderer::class)]
final class DataTableRendererTest extends TestCase
{
    public function testColumnsAreDeclaredAsKeyLabelPairs(): void
    {
        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => [
                    ['key' => 'method', 'label' => 'method'],
                    ['key' => 'path', 'label' => 'path'],
                ],
                'rows' => [
                    ['method' => 'GET', 'path' => '/agency'],
                ],
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 6)),
        );

        $text = implode("\n", $frame->lines);

        self::assertStringContainsString('method', $text);
        self::assertStringContainsString('GET', $text);
        self::assertStringContainsString('/agency', $text);
    }

    public function testColumnsGivenAsPlainStringsRenderNoData(): void
    {
        // Documented failure mode, not aspiration: strings are not a column
        // declaration, and the renderer drops them rather than guessing.
        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => ['method', 'path'],
                'rows' => [['method' => 'GET', 'path' => '/agency']],
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 6)),
        );

        self::assertStringNotContainsString('/agency', implode("\n", $frame->lines));
    }

    public function testTheFrameMatchesTheBoundsItWasGiven(): void
    {
        // The caller composites this into a buffer at these bounds without
        // clamping, so a renderer that overflows corrupts neighbouring cells.
        $bounds = new TuiBounds(0, 0, 32, 7);

        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => [['key' => 'name', 'label' => 'name']],
                'rows' => [['name' => 'uno'], ['name' => 'dos']],
            ]),
            new TuiRenderContext($bounds),
        );

        self::assertSame($bounds->width, $frame->width);
        self::assertSame($bounds->height, $frame->height);
        self::assertCount($bounds->height, $frame->lines);
    }

    public function testItSupportsOnlyItsOwnNodeType(): void
    {
        $renderer = new DataTableRenderer();

        self::assertTrue($renderer->supports(new TuiNode('t', 'data-table')));
        self::assertFalse($renderer->supports(new TuiNode('t', 'text')));
    }
}
