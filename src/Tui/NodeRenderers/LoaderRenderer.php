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

use Milpa\Live\Contracts\Tui\TuiNodeRendererInterface;
use Milpa\Live\Tui\TuiString;
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Animated loading spinner. The TUI analog of pi-tui's `Loader` /
 * `CancellableLoader`. Pure renderer: it draws whatever `frame` index
 * and `message` the node's props carry — animation advance (picking the
 * next frame on each tick) is the caller's job, not the renderer's, so
 * the renderer stays a deterministic node-in/frame-out mapping with no
 * internal clock. The caller drives animation by mutating
 * `props['frame']` from the `RetainedTuiLoop` `tick` callback or from
 * a live component's state.
 *
 * The default frame set is a braille spinner compatible with the lab's
 * mb-aware string utils; callers can pass a custom `frames` array.
 * When `aborted` is true, the spinner is replaced by a static glyph and
 * the message is dimmed via the `muted` theme role (applied by the
 * `TuiAnsiPainter` pass, not here).
 *
 * Node props (all optional):
 *  - `message` string  Text shown next to the spinner. Default: 'Loading…'.
 *  - `frame`   int     Index into `frames` (wrapped modulo). Default: 0.
 *  - `frames`  array   Custom spinner frames. Default: braille 8-step.
 *  - `aborted` bool    Show a static canceled state. Default: false.
 *  - `done`    bool    Show a static done state (checkmark). Default: false.
 *  - `color`   string  Theme role for the spinner glyph. Default: 'accent'.
 */
final class LoaderRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    private const DEFAULT_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * True only for `loader` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'loader';
    }

    /**
     * Draws the current spinner frame; the caller advances the animation by
     * re-rendering, since the renderer keeps no state.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $message = (string) ($node->props['message'] ?? 'Loading…');
        $aborted = (bool) ($node->props['aborted'] ?? false);
        $done = (bool) ($node->props['done'] ?? false);
        $frames = is_array($node->props['frames'] ?? null) && $node->props['frames'] !== []
            ? array_map('strval', $node->props['frames'])
            : self::DEFAULT_FRAMES;
        $frameIndex = (int) ($node->props['frame'] ?? 0);
        $width = $context->bounds->width;

        $glyph = match (true) {
            $done => '✓',
            $aborted => '◌',
            default => $frames[$frameIndex % count($frames)],
        };
        $role = match (true) {
            $done => 'success',
            $aborted => 'muted',
            default => (string) ($node->props['color'] ?? 'accent'),
        };

        $messageWidth = max(0, $width - 2);
        $fittedMessage = TuiString::truncate($message, $messageWidth);
        $line = $glyph . ' ' . TuiString::padEnd($fittedMessage, $messageWidth);
        $line = TuiString::padEnd(TuiString::truncate($line, $width), $width);

        $rows = [];
        for ($i = 0; $i < $context->bounds->height; $i++) {
            $rows[] = $i === 0 ? $line : str_repeat(' ', $width);
        }

        return $this->frame($width, $context->bounds->height, $rows);
    }
}
