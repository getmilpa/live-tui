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

use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\Tui\NodeRenderers\StatusBarRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Dispatch happens on the node's declared type, never on where the node
 * came from — that is what lets an unknown producer feed the tree.
 */
#[CoversClass(TuiNodeRendererRegistry::class)]
final class TuiNodeRendererRegistryTest extends TestCase
{
    public function testItResolvesByNodeType(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $table = new DataTableRenderer();
        $status = new StatusBarRenderer();

        $registry->register($table);
        $registry->register($status);

        self::assertSame($table, $registry->resolve(new TuiNode('a', 'data-table')));
        self::assertSame($status, $registry->resolve(new TuiNode('b', 'status-bar')));
    }

    public function testAnUnknownTypeResolvesToNothingRatherThanGuessing(): void
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());

        self::assertNull($registry->resolve(new TuiNode('a', 'no-such-type')));
    }
}
