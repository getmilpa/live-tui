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

namespace Milpa\Live\Tui;

use Milpa\Live\Contracts\Tui\VirtualTerminalBufferInterface;
use Milpa\Live\ValueObjects\Tui\TuiBounds;
use Milpa\Live\ValueObjects\Tui\TuiBufferDiff;
use Milpa\Live\ValueObjects\Tui\TuiFrame;

/**
 * A fixed grid of terminal cells that frames are composited into. Its `diff()`
 * against the previous buffer is what makes this runtime retained rather than
 * redrawn: only the rows it reports as changed are ever written out.
 */
final class VirtualTerminalBuffer implements VirtualTerminalBufferInterface
{
    /**
     * @var array<int, array<int, string>>
     */
    private array $cells;

    public function __construct(
        private readonly int $width,
        private readonly int $height,
        string $fill = ' ',
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Virtual terminal buffer dimensions must be positive.');
        }

        $cell = TuiString::slice($fill, 1) ?: ' ';
        $this->cells = array_fill(0, $height, array_fill(0, $width, $cell));
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * Composites the frame into this buffer at the given bounds, overwriting the
     * cells it covers and leaving every other cell untouched.
     */
    public function writeFrame(TuiBounds $bounds, TuiFrame $frame): void
    {
        foreach ($frame->lines as $row => $line) {
            $targetRow = $bounds->y + $row;
            if ($targetRow < 0 || $targetRow >= $this->height) {
                continue;
            }

            $cells = TuiString::cells($line);
            if ($cells === []) {
                continue;
            }

            foreach ($cells as $column => $cell) {
                if ($column >= $bounds->width) {
                    break;
                }

                $targetColumn = $bounds->x + $column;
                if ($targetColumn < 0 || $targetColumn >= $this->width) {
                    continue;
                }

                $this->cells[$targetRow][$targetColumn] = $cell;
            }
        }
    }

    /**
     * The buffer's current contents, one string per row.
     */
    public function lines(): array
    {
        return array_map(static fn (array $line): string => implode('', $line), $this->cells);
    }

    /**
     * The rows that differ from the previous buffer. An identical frame yields an
     * empty diff, which is what makes a repaint free.
     */
    public function diff(VirtualTerminalBufferInterface $previous): TuiBufferDiff
    {
        $previousLines = $previous->lines();
        $changes = [];

        foreach ($this->lines() as $row => $line) {
            if (($previousLines[$row] ?? null) !== $line) {
                $changes[] = ['row' => $row, 'line' => $line];
            }
        }

        return new TuiBufferDiff($changes);
    }
}
