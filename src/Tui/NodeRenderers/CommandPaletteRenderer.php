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
use Milpa\Live\ValueObjects\Tui\TuiFrame;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Live\ValueObjects\Tui\TuiRenderContext;

/**
 * Command palette overlay: a query line followed by the matching commands,
 * each with its optional shortcut, and a `›` marker on the one under the
 * cursor. Reads `query`, `commands` and `cursor` from the node's props.
 */
final class CommandPaletteRenderer extends AbstractTuiNodeRenderer implements TuiNodeRendererInterface
{
    /**
     * True only for `command-palette` nodes — dispatch is by declared node
     * type, never by where the node came from.
     */
    public function supports(TuiNode $node): bool
    {
        return $node->type === 'command-palette';
    }

    /**
     * Draws the query line and the command list inside the palette box.
     */
    public function render(TuiNode $node, TuiRenderContext $context): TuiFrame
    {
        $query = (string) ($node->props['query'] ?? '');
        $commands = is_array($node->props['commands'] ?? null) ? $node->props['commands'] : [];
        $lines = ['⌘ Search  ' . ($query !== '' ? $query : '(empty)')];

        foreach ($commands as $index => $command) {
            if (!is_array($command)) {
                continue;
            }
            $marker = $index === (int) ($node->props['cursor'] ?? 0) ? '›' : ' ';
            $shortcut = (string) ($command['shortcut'] ?? '');
            $lines[] = $marker . ' ' . (string) ($command['label'] ?? $command['command'] ?? 'Command')
                . ($shortcut !== '' ? '  ·  ' . $shortcut : '');
        }

        return $this->frame(
            $context->bounds->width,
            $context->bounds->height,
            $this->boxed('command palette', $lines, $context->bounds->width, $context->bounds->height, $context->focused($node)),
        );
    }
}
