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

use Milpa\Live\Tui\TuiString;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every renderer measures its output with these helpers, so a wrong width
 * here does not produce a wrong string — it produces a corrupted frame,
 * because the buffer composites at bounds without clamping.
 */
#[CoversClass(TuiString::class)]
final class TuiStringTest extends TestCase
{
    public function testWidthIsMeasuredInCellsNotBytes(): void
    {
        // Multi-byte, single-cell: bytes would say 2, the terminal shows 1.
        self::assertSame(5, TuiString::visibleLength('cañón'));
    }

    public function testAnsiSequencesDoNotCountTowardsWidth(): void
    {
        self::assertSame(4, TuiString::visibleLength("\033[31mrojo\033[0m"));
    }

    public function testTruncationMarksThatSomethingWasCut(): void
    {
        $cut = TuiString::truncate('abcdefghij', 5);

        self::assertSame(5, TuiString::visibleLength($cut));
        self::assertStringEndsWith('…', $cut);
    }

    public function testTextThatFitsIsLeftAlone(): void
    {
        self::assertSame('abc', TuiString::truncate('abc', 5));
    }

    public function testPaddingFillsToTheRequestedWidth(): void
    {
        self::assertSame(8, TuiString::visibleLength(TuiString::padEnd('abc', 8)));
    }
}
