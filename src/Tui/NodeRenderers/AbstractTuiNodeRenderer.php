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

namespace Milpa\Live\Tui\NodeRenderers;

use Milpa\Live\Tui\TuiFrameFactory;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;

/**
 * Shared drawing helpers for node renderers: frame construction, width-safe
 * fitting and padding, and the box chrome (with a heavier border when the
 * node holds focus). Holds no state between frames.
 */
abstract class AbstractTuiNodeRenderer
{
    /**
     * @param array<int, string> $lines
     */
    protected function frame(int $width, int $height, array $lines): TuiFrame
    {
        return TuiFrameFactory::fromLines($width, $height, $lines);
    }

    protected function fit(string $text, int $width): string
    {
        return TuiString::truncate(TuiString::clean($text), $width);
    }

    protected function pad(string $text, int $width): string
    {
        return TuiString::padEnd($text, $width);
    }

    /**
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    protected function boxed(string $title, array $lines, int $width, int $height, bool $focused = false): array
    {
        if ($width < 4 || $height < 3) {
            return array_map(fn (string $line): string => $this->fit($line, $width), array_slice($lines, 0, $height));
        }

        $inner = $width - 4;
        $top = ($focused ? '╭' : '┌') . str_repeat('─', $width - 2) . ($focused ? '╮' : '┐');
        $separator = '├' . str_repeat('─', $width - 2) . '┤';
        $bottom = ($focused ? '╰' : '└') . str_repeat('─', $width - 2) . ($focused ? '╯' : '┘');
        $title = ($focused ? '› ' : '  ') . $title;
        $output = [
            $top,
            '│ ' . $this->pad($this->fit($title, $inner), $inner) . ' │',
            $separator,
        ];

        foreach (array_slice($lines, 0, max(0, $height - 4)) as $line) {
            $output[] = '│ ' . $this->pad($this->fit($line, $inner), $inner) . ' │';
        }
        $output[] = $bottom;

        return $output;
    }
}
