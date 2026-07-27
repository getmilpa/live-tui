<?php

declare(strict_types=1);

namespace Milpa\Live\Tui;

use Milpa\Live\ValueObjects\Tui\TuiFrame;

/**
 * Builds a width/height-normalized {@see TuiFrame} from raw text lines,
 * padding each line out to the target width via {@see TuiString}. Lives
 * here -- outside ValueObjects\Tui -- so the TuiFrame value object itself
 * never has to import a concrete implementation-layer helper; only code
 * that already depends on Tui\* needs to know how lines get padded.
 */
final class TuiFrameFactory
{
    private function __construct()
    {
    }

    /**
     * Builds a frame exactly `$width` x `$height` in size. Rows beyond
     * the end of `$lines` are filled blank; every row (existing or
     * filled) is padded out to `$width` via {@see TuiString::padEnd()}.
     * This does not truncate overly-long lines — callers that need a
     * hard width ceiling (e.g. {@see \Milpa\Live\Tui\NodeRenderers\ComponentTuiNodeRenderer})
     * are responsible for their own truncation before/after calling this.
     *
     * @param array<int, string> $lines Source lines; missing rows (when `count($lines) < $height`) are treated
     *                                  as empty.
     */
    public static function fromLines(int $width, int $height, array $lines): TuiFrame
    {
        $normalized = [];
        for ($row = 0; $row < $height; $row++) {
            $normalized[] = TuiString::padEnd((string) ($lines[$row] ?? ''), $width);
        }

        return new TuiFrame($width, $height, $normalized);
    }
}
