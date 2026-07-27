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

use Milpa\Live\Tui\VirtualTerminalBuffer;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The buffer diff is what makes this runtime retained instead of redrawn:
 * everything that reaches the terminal is a consequence of the rows this
 * reports as changed. These tests pin what it emits — and what it does not.
 */
#[CoversClass(VirtualTerminalBuffer::class)]
final class VirtualTerminalBufferTest extends TestCase
{
    public function testTwoUntouchedBuffersProduceNoChanges(): void
    {
        $previous = new VirtualTerminalBuffer(10, 3);
        $current = new VirtualTerminalBuffer(10, 3);

        self::assertTrue($current->diff($previous)->isEmpty());
    }

    public function testRepaintingTheSameContentProducesNoChanges(): void
    {
        $frame = new TuiFrame(4, 1, ['hola']);

        $previous = new VirtualTerminalBuffer(10, 3);
        $previous->writeFrame(new TuiBounds(0, 1, 4, 1), $frame);

        $current = new VirtualTerminalBuffer(10, 3);
        $current->writeFrame(new TuiBounds(0, 1, 4, 1), $frame);

        // This is the whole point of a retained runtime: an identical frame
        // costs zero writes, not a full repaint.
        self::assertTrue($current->diff($previous)->isEmpty());
    }

    public function testOnlyTheChangedRowIsReported(): void
    {
        $previous = new VirtualTerminalBuffer(10, 3);
        $previous->writeFrame(new TuiBounds(0, 1, 4, 1), new TuiFrame(4, 1, ['hola']));

        $current = new VirtualTerminalBuffer(10, 3);
        $current->writeFrame(new TuiBounds(0, 1, 4, 1), new TuiFrame(4, 1, ['adio']));

        $changes = $current->diff($previous)->changes;

        self::assertCount(1, $changes);
        self::assertSame(1, $changes[0]['row']);
        self::assertStringStartsWith('adio', $changes[0]['line']);
    }

    public function testWritingAtBoundsLeavesOtherRowsUntouched(): void
    {
        $buffer = new VirtualTerminalBuffer(6, 3);
        $buffer->writeFrame(new TuiBounds(2, 1, 3, 1), new TuiFrame(3, 1, ['abc']));

        $lines = $buffer->lines();

        self::assertSame('      ', $lines[0]);
        self::assertSame('  abc ', $lines[1]);
        self::assertSame('      ', $lines[2]);
    }

    public function testDimensionsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VirtualTerminalBuffer(0, 3);
    }
}
