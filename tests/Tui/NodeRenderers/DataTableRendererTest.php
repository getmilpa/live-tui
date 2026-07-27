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
use Milpa\Live\Tui\TuiString;
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

    public function testACellEndingInASeparatorByteIsNotCorrupted(): void
    {
        // `rtrim($row, ' │ ')` treats its argument as a set of BYTES, and `│`
        // is E2 94 82. An em dash is E2 80 94, so its final byte collided with
        // the mask and got eaten, leaving broken UTF-8 that took the row's
        // visible width to zero.
        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => [['key' => 'k', 'label' => 'k'], ['key' => 'v', 'label' => 'v']],
                'rows' => [['k' => 'dash', 'v' => '—']],
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );

        foreach ($frame->lines as $index => $line) {
            self::assertTrue(
                mb_check_encoding($line, 'UTF-8'),
                "Row {$index} carries broken UTF-8.",
            );
            self::assertSame(40, TuiString::visibleLength($line), "Row {$index} is not 40 cells wide.");
        }

        self::assertStringContainsString('—', implode("\n", $frame->lines));
    }

    public function testAColumnLabelEndingInASeparatorByteIsNotCorrupted(): void
    {
        // Same rtrim byte-mask defect as the data rows, but on the HEADER row,
        // whose labels come from the host just as freely as the cells do.
        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => [['key' => 'k', 'label' => 'k'], ['key' => 'v', 'label' => 'estado —']],
                'rows' => [['k' => 'a', 'v' => 'b']],
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 40, 4)),
        );

        foreach ($frame->lines as $index => $line) {
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "Row {$index} carries broken UTF-8.");
            self::assertSame(40, TuiString::visibleLength($line), "Row {$index} is not 40 cells wide.");
        }
    }

    public function testFalseAndNullStayDistinguishableFromAnAbsentCell(): void
    {
        // A plain (string) cast renders false as '' — indistinguishable from
        // null, from an empty string, and from a column the row lacks. In a
        // data table that is data loss, not formatting.
        $frame = (new DataTableRenderer())->render(
            new TuiNode('t', 'data-table', props: [
                'columns' => [['key' => 'k', 'label' => 'k'], ['key' => 'v', 'label' => 'v']],
                'rows' => [
                    ['k' => 'yes', 'v' => true],
                    ['k' => 'no', 'v' => false],
                    ['k' => 'nothing', 'v' => null],
                    ['k' => 'absent'],
                ],
            ]),
            new TuiRenderContext(new TuiBounds(0, 0, 44, 7)),
        );

        $text = implode("\n", $frame->lines);

        self::assertStringContainsString('true', $text);
        self::assertStringContainsString('false', $text, 'A present false must be visible.');
        self::assertStringContainsString('—', $text, 'An explicit null must be visible.');
    }

    public function testTheFilterSearchesWhatTheTableActuallyShows(): void
    {
        // Display and filter have to agree. Rendering false as "false" while
        // the filter still saw '' meant a value you could read but not find.
        $node = static fn (string $filter): TuiNode => new TuiNode('t', 'data-table', props: [
            'columns' => [['key' => 'k', 'label' => 'k'], ['key' => 'v', 'label' => 'v']],
            'rows' => [['k' => 'one', 'v' => true], ['k' => 'two', 'v' => false]],
            'filter' => $filter,
        ]);

        $render = static fn (string $filter): string => implode(
            ' ',
            (new DataTableRenderer())->render($node($filter), new TuiRenderContext(new TuiBounds(0, 0, 40, 5)))->lines,
        );

        self::assertStringContainsString('false', $render(''), 'Unfiltered, the false row is visible.');
        self::assertStringContainsString('false', $render('false'), 'So it must also be findable.');
        self::assertStringNotContainsString('true', $render('false'), 'And the filter must still filter.');
    }

    public function testItSupportsOnlyItsOwnNodeType(): void
    {
        $renderer = new DataTableRenderer();

        self::assertTrue($renderer->supports(new TuiNode('t', 'data-table')));
        self::assertFalse($renderer->supports(new TuiNode('t', 'text')));
    }
}
