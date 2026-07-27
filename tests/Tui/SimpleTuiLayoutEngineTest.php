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

use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiLayoutFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Layout resolves the tree into bounds per node plus a paint order; the
 * renderers never compute geometry themselves.
 */
#[CoversClass(SimpleTuiLayoutEngine::class)]
#[CoversClass(TuiLayoutFrame::class)]
final class SimpleTuiLayoutEngineTest extends TestCase
{
    public function testEveryNodeInTheTreeGetsBounds(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => 'uno']),
                new TuiNode('b', 'text', props: ['text' => 'dos']),
            ]),
            new TuiBounds(0, 0, 40, 6),
        );

        self::assertArrayHasKey('root', $frame->bounds);
        self::assertArrayHasKey('a', $frame->bounds);
        self::assertArrayHasKey('b', $frame->bounds);
    }

    public function testChildrenStayInsideTheViewport(): void
    {
        $viewport = new TuiBounds(0, 0, 40, 6);

        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => 'uno']),
            ]),
            $viewport,
        );

        foreach ($frame->bounds as $id => $bounds) {
            self::assertLessThanOrEqual(
                $viewport->width,
                $bounds->x + $bounds->width,
                "El nodo {$id} se sale del viewport a lo ancho.",
            );
            self::assertLessThanOrEqual(
                $viewport->height,
                $bounds->y + $bounds->height,
                "El nodo {$id} se sale del viewport a lo alto.",
            );
        }
    }

    public function testThePaintOrderCoversTheTree(): void
    {
        $frame = (new SimpleTuiLayoutEngine())->layout(
            new TuiNode('root', 'box', children: [
                new TuiNode('a', 'text', props: ['text' => 'uno']),
            ]),
            new TuiBounds(0, 0, 40, 6),
        );

        self::assertContains('root', $frame->paintOrder);
        self::assertContains('a', $frame->paintOrder);
    }
}
