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

namespace Milpa\Live\Tests\ValueObjects\Tui;

use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The tree's invariants are enforced at construction so an ill-formed node
 * cannot reach a renderer, where it would fail as a blank frame instead of
 * an error.
 */
#[CoversClass(TuiNode::class)]
#[CoversClass(TuiBounds::class)]
#[CoversClass(TuiFrame::class)]
final class TuiNodeTest extends TestCase
{
    public function testANodeNeedsAnIdAndAType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TuiNode('', 'text');
    }

    public function testANodeNeedsANonEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TuiNode('a', '');
    }

    public function testNodesNest(): void
    {
        $root = new TuiNode('root', 'box', children: [
            new TuiNode('a', 'text', props: ['text' => 'hola']),
        ]);

        self::assertCount(1, $root->children);
        self::assertSame('a', $root->children[0]->id);
    }

    public function testBoundsCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TuiBounds(0, 0, -1, 4);
    }

    public function testFrameDimensionsCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TuiFrame(-1, 1, []);
    }
}
